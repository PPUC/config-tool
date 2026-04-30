(function (Drupal, once) {
  Drupal.behaviors.ppucClaroSidebar = {
    attach(context) {
      once('ppuc-claro-sidebar', '.sidebar', context).forEach((sidebar) => {
        const targets = [
          {
            path: /\/node\/\d+\/all-switches\/?$/,
            selector:
              '[id*="game-assets-block-switch-locations"]:not([id*="edit"]), [class*="game-assets-block-switch-locations"]:not([class*="edit"])',
          },
          {
            path: /\/node\/\d+\/all-lamps\/?$/,
            selector:
              '[id*="game-assets-block-lamp-locations"], [class*="game-assets-block-lamp-locations"]',
          },
        ];

        const targetConfig = targets.find(({ path }) =>
          path.test(window.location.pathname),
        );

        if (!targetConfig) {
          return;
        }

        const target = sidebar.querySelector(targetConfig.selector);

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
