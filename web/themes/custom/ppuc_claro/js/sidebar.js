(function (Drupal, once) {
  Drupal.behaviors.ppucClaroSidebar = {
    attach(context) {
      once('ppuc-claro-sidebar', '.sidebar', context).forEach((sidebar) => {
        if (!/\/node\/\d+\/all-switches\/?$/.test(window.location.pathname)) {
          return;
        }

        const target = sidebar.querySelector(
          '[id*="game-assets-block-switch-locations"]:not([id*="edit"]), [class*="game-assets-block-switch-locations"]:not([class*="edit"])',
        );

        if (!target) {
          return;
        }

        const scrollTargetIntoSidebarView = () => {
          const sidebarRect = sidebar.getBoundingClientRect();
          const targetRect = target.getBoundingClientRect();
          const topOffset = targetRect.top - sidebarRect.top;
          const bottomOffset = targetRect.bottom - sidebarRect.bottom;

          if (topOffset < 0 || bottomOffset > 0) {
            sidebar.scrollTop += topOffset;
          }
        };

        window.requestAnimationFrame(scrollTargetIntoSidebarView);
        window.setTimeout(scrollTargetIntoSidebarView, 250);
      });
    },
  };
})(Drupal, once);
