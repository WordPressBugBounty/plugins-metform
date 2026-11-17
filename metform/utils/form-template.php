<?php
namespace MetForm\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Compile Elementor form HTML without treating ordinary widget text as JS.
 *
 * Only installed MetForm field renderers may emit bindings. Their output is
 * kept in a per-render table, never identified by attacker-controlled markup.
 */
class Form_Template {

	private static $active = [];
	private static $current;
	private $bindings = [];
	private $prefix;
	
	/**
	 * Class-name prefixes of MetForm Free and Pro field widgets.
	 *
	 * Legacy widgets are declared as \Elementor\MetForm_*. Renamed widgets live
	 * in the plugins' own namespaces; their old names are only class_alias()es,
	 * and get_class() always reports the real (new) name.
	 */
	private const TRUSTED_WIDGET_PREFIXES = [
		'Elementor\\MetForm_',
		'MetForm\\Widgets\\',
		'MetForm_Pro\\Widgets\\',
	];


	public function __construct() {
		$this->prefix = 'MFTEMPLATE' . bin2hex( random_bytes( 16 ) );
	}

	private function protect( $source ) {
		$token = $this->prefix . count( $this->bindings ) . 'END';
		$this->bindings[ $token ] = $source;
		return $token;
	}

	/** Escape literal markup, preserving only bindings registered by PHP. */
	public function compile( $content ) {
		$content = Util::mfConvertStyleToReactObj( $content, function ( $expression ) {
			return $this->protect( $expression );
		} );
		$content = $this->normalize( $content );
		$content = Util::esc_mflt( $content );
		$content = strtr( $content, $this->bindings );

		// HTML parses closing script tags even inside a JS template literal.
		return str_ireplace( '</script', '\\x3c/script', $content );
	}

	private function normalize( $content ) {
		$content = str_replace(
			[ 'class=', 'for=', 'cellspacing=', 'cellpadding=', 'srcset', 'colspan', '<br>', '<BR>' ],
			[ 'className=', 'htmlFor=', 'cellSpacing=', 'cellPadding=', 'srcSet', 'colSpan', '<br/>', '<br/>' ],
			$content
		);
		return preg_replace( '/<!--(.|\s)*?-->/', '', $content );
	}

	/** Capture bindings from MetForm field widgets, never from markup or settings. */
	public function capture_widget( $content, $widget ) {
		if ( self::$current !== $this || ! $this->is_trusted_widget( $widget ) ) {
			return $content;
		}

		$content = Util::mfConvertStyleToReactObj( $content );
		$content = Util::esc_mflt_html_attributes( $this->normalize( $content ) );
		return $this->protect( $content );
	}

	/**
	 * Whether a widget's output may keep its template bindings.
	 *
	 * MetForm Free and Pro field widgets escape their settings with esc_mflt_*.
	 * Other Elementor widgets echo settings raw, so their output stays literal.
	 * get_class() names the concrete class, so a subclass that overrides
	 * render() is not trusted through its MetForm parent.
	 *
	 * @param \Elementor\Widget_Base $widget Widget being rendered.
	 * @return bool
	 */
	private function is_trusted_widget( $widget ) {
		$trusted = false;
		if ( 0 === strpos( $widget->get_name(), 'mf-' ) ) {
			$class = get_class( $widget );
			foreach ( self::TRUSTED_WIDGET_PREFIXES as $prefix ) {
				// PHP class names are case-insensitive.
				if ( 0 === stripos( $class, $prefix ) ) {
					$trusted = true;
					break;
				}
			}
		}

		/**
		 * Filters whether a widget inside a MetForm form may emit template bindings.
		 *
		 * Return true only for widgets whose render() escapes every setting
		 * with Util::esc_mflt_react_entity() or an equivalent helper.
		 *
		 * @since 4.3.5
		 *
		 * @param bool                   $trusted Whether the widget is trusted.
		 * @param \Elementor\Widget_Base $widget  Widget being rendered.
		 */
		return (bool) apply_filters( 'metform/form_template/trusted_widget', $trusted, $widget );
	}

	/** Render from a checked form ID. Never accept a caller's raw HTML as code. */
	public function render( $form_id ) {
		$form_id = (int) $form_id;
		if ( isset( self::$active[ $form_id ] ) ) {
			return '';
		}

		$elementor = \Elementor\Plugin::instance();
		$was_editing = $elementor->editor->is_edit_mode();
		$previous = self::$current;
		self::$current = $this;
		self::$active[ $form_id ] = true;
		$disable_cache = static function () { return 'disable'; };
		add_filter( 'elementor/widget/render_content', [ $this, 'capture_widget' ], PHP_INT_MAX, 2 );
		// Render-local tokens must never enter Elementor's persistent HTML cache.
		add_filter( 'pre_option_elementor_element_cache_ttl', $disable_cache );
		$elementor->editor->set_edit_mode( false );

		try {
			if ( ! wp_style_is( 'elementor-frontend', 'registered' ) ) {
				$elementor->frontend->register_styles();
			}
			// The public display helper rejects the current document ID. We own
			// the recursion guard here so form permalinks can use this same path.
			$content = $elementor->frontend->get_builder_content( $form_id, true );
			if ( '' === $content ) {
				$content = get_post_field( 'post_content', $form_id );
			}
			return $this->compile( $content );
		} finally {
			$elementor->editor->set_edit_mode( $was_editing );
			remove_filter( 'elementor/widget/render_content', [ $this, 'capture_widget' ], PHP_INT_MAX );
			remove_filter( 'pre_option_elementor_element_cache_ttl', $disable_cache );
			unset( self::$active[ $form_id ] );
			self::$current = $previous;
		}
	}
}
