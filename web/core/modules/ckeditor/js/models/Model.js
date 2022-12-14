/**
* DO NOT EDIT THIS FILE.
* See the following change record for more information,
* https://www.drupal.org/node/2815083
* @preserve
**/
(function (Drupal, Backbone) {
  Drupal.ckeditor.Model = Backbone.Model.extend({
    defaults: {
      activeEditorConfig: null,
      $textarea: null,
      isDirty: false,
      hiddenEditorConfig: null,
      buttonsToFeatures: null,
      featuresMetadata: null,
      groupNamesVisible: false
    },
    sync: function sync() {
      this.get('$textarea').val(JSON.stringify(this.get('activeEditorConfig')));
    }
  });
})(Drupal, Backbone);