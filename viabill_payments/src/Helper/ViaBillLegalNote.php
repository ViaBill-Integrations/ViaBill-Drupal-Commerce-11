<?php

namespace Drupal\viabill_payments\Helper;

use Drupal\Component\Gettext\PoStreamReader;
use Drupal\Component\Utility\Xss;

/**
 * Legal note (credit warning) shown next to ViaBill in the checkout.
 *
 * It is configured in the "Legal Note" fieldset of the ViaBill payment
 * gateway: it can be switched off, and the merchant can replace the default
 * text with their own. The default text is translated in the module's
 * translations/*.po files and is shown either in the current language
 * ("auto") or in a fixed language.
 */
final class ViaBillLegalNote {

  // Default legal note. It must be identical to the text in getDefaultText(),
  // which is the one the translation tools pick up.
  public const DEFAULT_TEXT = 'Warning! Borrow responsibly - it costs money.';

  public const LANGUAGE_AUTO = 'auto';

  // HTML tags the legal note may contain, e.g. a link to the terms.
  public const ALLOWED_TAGS = ['a', 'b', 'strong', 'em', 'i', 'br', 'p', 'span'];

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
   * Returns the legal note to show for a ViaBill payment gateway.
   *
   * @param array $configuration
   *   The payment gateway configuration.
   * @param string $langcode
   *   The current language code, used for the "auto" language.
   *
   * @return string
   *   Safe HTML: the merchant's own text if set, otherwise the default text.
   *   Empty string if the legal note is switched off.
   */
  public static function getText(array $configuration, $langcode) {
    if (isset($configuration['legal_note_enabled']) && !$configuration['legal_note_enabled']) {
      return '';
    }

    $text = trim((string) ($configuration['legal_note_text'] ?? ''));

    if ($text === '') {
      $language = (string) ($configuration['legal_note_language'] ?? self::LANGUAGE_AUTO);
      $text = self::getDefaultText($language === self::LANGUAGE_AUTO ? $langcode : $language);
    }

    return Xss::filter($text, self::ALLOWED_TAGS);
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
