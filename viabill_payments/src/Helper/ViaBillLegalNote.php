<?php

namespace Drupal\viabill_payments\Helper;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Utility\Xss;

/**
 * Legal note (credit warning) shown next to ViaBill in the checkout.
 *
 * It is always shown. The text is the default legal note, translated in the
 * module's translations/*.po files, in the current language ("auto") or in a
 * fixed language. The merchant can only change how it looks, in the "Legal
 * Note" fieldset of the ViaBill payment gateway.
 */
final class ViaBillLegalNote {

  // Default legal note. It must be identical to the text in getDefaultText(),
  // which is the one the translation tools pick up.
  public const DEFAULT_TEXT = 'Warning! Borrow responsibly - it costs money.';

  public const LANGUAGE_AUTO = 'auto';

  // HTML tags the legal note may contain, e.g. a link to the terms.
  public const ALLOWED_TAGS = ['a', 'b', 'strong', 'em', 'i', 'br', 'p', 'span'];

  // Default font style: the same as the payment method name.
  public const DEFAULT_FONT_STYLE = 'normal';

  // Default font size: a bit smaller than the payment method name. It is
  // relative to the font size of the theme, so it is safe for every theme.
  public const DEFAULT_FONT_SIZE = '85%';

  // Default margins of the legal note, keyed by configuration key. Negative
  // margins depend on the theme, so the defaults are neutral and safe for
  // every theme.
  public const DEFAULT_MARGINS = [
    'legal_note_margin_top' => '0px',
    'legal_note_margin_bottom' => '0px',
    'legal_note_margin_left' => '0px',
  ];

  /**
   * Translations read from the module's .po files, keyed by language code.
   *
   * @var array
   */
  private static $translations = [];

  /**
   * Returns the options of the language setting.
   *
   * @return array
   *   Labels keyed by language code.
   */
  public static function getLanguageOptions() {
    return [
      self::LANGUAGE_AUTO => t('Auto'),
      'en' => t('English'),
      'da' => t('Danish'),
      'es' => t('Spanish'),
    ];
  }

  /**
   * Returns the options of the font style setting.
   *
   * @return array
   *   Labels keyed by font style.
   */
  public static function getFontStyleOptions() {
    return [
      'normal' => t('Normal'),
      'bold' => t('Bold'),
      'italic' => t('Italic'),
      'bold-italic' => t('Bold italic'),
    ];
  }

  /**
   * Returns the options of the text alignment setting.
   *
   * @return array
   *   Labels keyed by alignment; the empty key is the alignment of the theme.
   */
  public static function getAlignOptions() {
    return [
      '' => t('Theme default'),
      'left' => t('Left'),
      'center' => t('Center'),
      'right' => t('Right'),
    ];
  }

  /**
   * Returns a valid CSS font size. A plain number is taken as pixels.
   *
   * @param mixed $value
   *   The value entered by the merchant.
   *
   * @return string
   *   The font size, e.g. '14px', or empty string if it is not valid.
   */
  public static function sanitizeFontSize($value) {
    $value = is_string($value) ? strtolower(trim($value)) : '';
    $value = str_replace(',', '.', $value);

    if (!preg_match('/^(\d+(?:\.\d+)?|\.\d+)\s*(px|em|rem|pt|%)?$/', $value, $matches)) {
      return '';
    }
    if ((float) $matches[1] <= 0) {
      return '';
    }

    return $matches[1] . (empty($matches[2]) ? 'px' : $matches[2]);
  }

  /**
   * Returns a valid CSS color (hex or a color name).
   *
   * @param mixed $value
   *   The value entered by the merchant.
   *
   * @return string
   *   The color, or empty string if it is not valid.
   */
  public static function sanitizeColor($value) {
    $value = is_string($value) ? trim($value) : '';

    return preg_match('/^(#[0-9a-fA-F]{3}|#[0-9a-fA-F]{6}|[a-zA-Z]{3,20})$/', $value) ? $value : '';
  }

  /**
   * Returns the extra CSS declarations in a form that is safe to print.
   *
   * Only declarations (e.g. "padding: 8px; border: 1px solid #ccc") are
   * supported, so anything that could open a new rule or a tag is removed.
   *
   * @param mixed $css
   *   The value entered by the merchant.
   *
   * @return string
   *   The declarations, for use inside a style attribute.
   */
  public static function sanitizeExtraCss($css) {
    if (!is_string($css) || trim($css) === '') {
      return '';
    }

    $css = strip_tags($css);
    $css = preg_replace('#/\*.*?\*/#s', '', $css);
    $css = str_replace(['{', '}', '<', '>', '\\'], '', $css);
    $css = preg_replace('/expression\s*\(|javascript\s*:|@import/i', '', $css);
    $css = preg_replace('/\s+/', ' ', $css);

    return trim($css, " ;\t\n\r");
  }

  /**
   * Returns the inline style of the legal note, built from the settings.
   *
   * @param array $configuration
   *   The payment gateway configuration.
   *
   * @return string
   *   CSS declarations, e.g. "font-weight:600;color:#cc0000". Not escaped.
   */
  public static function getStyle(array $configuration) {
    $declarations = [];

    // Gateways saved before this setting existed get the default font size;
    // an empty value means the font size of the theme.
    $font_size = self::sanitizeFontSize($configuration['legal_note_font_size'] ?? self::DEFAULT_FONT_SIZE);
    if ($font_size !== '') {
      $declarations[] = 'font-size:' . $font_size;
    }

    // Gateways saved before the styling settings existed have no value yet,
    // so they get the default font style.
    $font_style = (string) ($configuration['legal_note_font_style'] ?? '');
    if (!in_array($font_style, ['normal', 'bold', 'italic', 'bold-italic'], TRUE)) {
      $font_style = self::DEFAULT_FONT_STYLE;
    }
    $declarations[] = 'font-weight:' . (strpos($font_style, 'bold') !== FALSE ? '600' : 'normal');
    $declarations[] = 'font-style:' . (strpos($font_style, 'italic') !== FALSE ? 'italic' : 'normal');

    $color = self::sanitizeColor($configuration['legal_note_color'] ?? '');
    if ($color !== '') {
      $declarations[] = 'color:' . $color;
    }

    $align = (string) ($configuration['legal_note_align'] ?? '');
    if (in_array($align, ['left', 'center', 'right'], TRUE)) {
      $declarations[] = 'text-align:' . $align;
    }

    $declarations[] = 'margin-top:' . self::getMargin($configuration, 'legal_note_margin_top');
    $declarations[] = 'margin-bottom:' . self::getMargin($configuration, 'legal_note_margin_bottom');
    $declarations[] = 'margin-left:' . self::getMargin($configuration, 'legal_note_margin_left');

    // The extra CSS comes last, so it can override the controls above.
    $extra_css = self::sanitizeExtraCss($configuration['legal_note_extra_css'] ?? '');
    if ($extra_css !== '') {
      $declarations[] = $extra_css;
    }

    return implode(';', $declarations);
  }

  /**
   * Returns a valid CSS margin. A plain number is taken as pixels.
   *
   * Negative values are allowed, e.g. to move the legal note closer to the
   * payment method name.
   *
   * @param mixed $value
   *   The value entered by the merchant.
   *
   * @return string
   *   The margin, e.g. '-12px', or empty string if it is not valid.
   */
  public static function sanitizeMargin($value) {
    $value = is_string($value) ? strtolower(trim($value)) : '';
    $value = str_replace(',', '.', $value);

    if (!preg_match('/^(-?)(\d+(?:\.\d+)?|\.\d+)\s*(px|em|rem|pt|%)?$/', $value, $matches)) {
      return '';
    }
    if ((float) $matches[2] == 0) {
      return '0px';
    }

    return $matches[1] . $matches[2] . (empty($matches[3]) ? 'px' : $matches[3]);
  }

  /**
   * Returns the default of one of the margin settings.
   *
   * @param string $key
   *   'legal_note_margin_top', 'legal_note_margin_bottom' or
   *   'legal_note_margin_left'.
   *
   * @return string
   *   The default margin.
   */
  public static function getDefaultMargin($key) {
    return self::DEFAULT_MARGINS[$key] ?? '0px';
  }

  /**
   * Returns one of the margins of a gateway configuration, or its default.
   *
   * @param array $configuration
   *   The payment gateway configuration.
   * @param string $key
   *   'legal_note_margin_top', 'legal_note_margin_bottom' or
   *   'legal_note_margin_left'.
   *
   * @return string
   *   The margin.
   */
  public static function getMargin(array $configuration, $key) {
    $margin = self::sanitizeMargin($configuration[$key] ?? '');
    return $margin !== '' ? $margin : self::getDefaultMargin($key);
  }

  /**
   * Returns the legal note to show for a ViaBill payment gateway.
   *
   * @param array $configuration
   *   The payment gateway configuration.
   * @param string $langcode
   *   The current language code, used for the "auto" language.
   *
   * @return string
   *   Safe HTML: the default text in the language of the language setting.
   */
  public static function getText(array $configuration, $langcode) {
    $language = (string) ($configuration['legal_note_language'] ?? self::LANGUAGE_AUTO);
    $text = self::getDefaultText($language === self::LANGUAGE_AUTO ? $langcode : $language);

    return Xss::filter($text, self::ALLOWED_TAGS);
  }

  /**
   * Returns the default legal note in each language of the language setting.
   *
   * @param string $langcode
   *   The current language code, used for the "auto" language.
   *
   * @return array
   *   Texts keyed like the options of the language setting.
   */
  public static function getDefaultTexts($langcode) {
    $texts = [];
    foreach (array_keys(self::getLanguageOptions()) as $language) {
      $texts[$language] = self::getDefaultText($language === self::LANGUAGE_AUTO ? $langcode : $language);
    }
    return $texts;
  }

  /**
   * Returns the default legal note in the given language.
   *
   * The module's own translation file is read directly, so the language does
   * not have to be enabled on the site. Otherwise the text goes through t(),
   * so it can also be translated in the user interface.
   *
   * @param string $langcode
   *   A language code, e.g. 'da'.
   *
   * @return string
   *   The default legal note.
   */
  public static function getDefaultText($langcode) {
    $langcode = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $langcode));

    if ($langcode === '' || $langcode === 'en') {
      return self::DEFAULT_TEXT;
    }

    $translations = self::getPoTranslations($langcode);
    if (!empty($translations[self::DEFAULT_TEXT])) {
      return $translations[self::DEFAULT_TEXT];
    }

    return (string) t('Warning! Borrow responsibly - it costs money.', [], ['langcode' => $langcode]);
  }

  /**
   * Reads the translations of the module's .po file for a language.
   *
   * @param string $langcode
   *   A language code, e.g. 'da'.
   *
   * @return array
   *   Translations keyed by source text.
   */
  private static function getPoTranslations($langcode) {
    if (!isset(self::$translations[$langcode])) {
      self::$translations[$langcode] = [];
      $file = dirname(__DIR__, 2) . '/translations/viabill_payments.' . $langcode . '.po';

      if (is_file($file)) {
        $reader = new PoStreamReader();
        $reader->setURI($file);
        $reader->open();
        while ($item = $reader->readItem()) {
          if (!$item->isPlural() && $item->getTranslation() !== '') {
            self::$translations[$langcode][$item->getSource()] = $item->getTranslation();
          }
        }
      }
    }

    return self::$translations[$langcode];
  }

}
