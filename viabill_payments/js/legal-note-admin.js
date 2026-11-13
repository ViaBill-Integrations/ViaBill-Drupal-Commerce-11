/**
 * @file
 * Shows the legal note in the language selected in the "Language" field of
 * the ViaBill payment gateway form, see ViaBillPayments::buildConfigurationForm().
 */
(function (Drupal, once) {
  Drupal.behaviors.viabillLegalNoteText = {
    attach(context) {
      once('viabill-legal-note-text', '.viabill-legal-note-text', context).forEach((text) => {
        const texts = JSON.parse(text.getAttribute('data-viabill-legal-note-texts') || '{}');
        const name = text.getAttribute('data-viabill-legal-note-language');
        const select = text.form ? text.form.querySelector(`select[name="${CSS.escape(name)}"]`) : null;
        if (!select) {
          return;
        }
        const update = () => {
          if (Object.prototype.hasOwnProperty.call(texts, select.value)) {
            text.value = texts[select.value];
          }
        };
        select.addEventListener('change', update);
        update();
      });
    },
  };
})(Drupal, once);
