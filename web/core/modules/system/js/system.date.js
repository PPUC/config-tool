/**
* DO NOT EDIT THIS FILE.
* See the following change record for more information,
* https://www.drupal.org/node/2815083
* @preserve
**/
(function ($, Drupal, drupalSettings) {
  var dateFormats = drupalSettings.dateFormats;
  Drupal.behaviors.dateFormat = {
    attach: function attach(context) {
      var source = once('dateFormat', '[data-drupal-date-formatter="source"]', context);
      var target = once('dateFormat', '[data-drupal-date-formatter="preview"]', context);
      if (!source.length || !target.length) {
        return;
      }
      function dateFormatHandler(e) {
        var baseValue = e.target.value || '';
        var dateString = baseValue.replace(/\\?(.?)/gi, function (key, value) {
          return dateFormats[key] ? dateFormats[key] : value;
        });
        target.forEach(function (item) {
          item.querySelectorAll('em').forEach(function (em) {
            em.textContent = dateString;
          });
        });
        $(target).toggleClass('js-hide', !dateString.length);
      }
      $(source).on('keyup.dateFormat change.dateFormat input.dateFormat', dateFormatHandler).trigger('keyup');
    }
  };
})(jQuery, Drupal, drupalSettings);