(function (Drupal, once) {
  Drupal.behaviors.provenzaPortal = {
    attach(context) {
      once('provenza-tech', 'details.provenza-tech', context);
    },
  };
})(Drupal, once);
