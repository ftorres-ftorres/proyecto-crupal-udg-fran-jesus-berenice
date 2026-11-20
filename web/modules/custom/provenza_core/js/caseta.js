(function (Drupal, once) {
  Drupal.behaviors.provenzaCaseta = {
    attach(context) {
      once('provenza-caseta', '.provenza-caseta', context);
    },
  };
})(Drupal, once);
