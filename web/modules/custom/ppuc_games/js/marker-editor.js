/**
 * @file
 * Draws a slide's markers on the slide's own photograph.
 *
 * Markers are coordinates over the picture, 0 to 1 in each axis, so they
 * survive being scaled to whatever panel a machine has. Typing those numbers
 * into a textarea is a poor way to say "this target, approached from that
 * flipper", so this puts the photograph on the form and lets the arrows be
 * drawn on it, in the same shape PPUC will draw them.
 *
 * The textarea stays the record. This parses it on the way in and rewrites it
 * on the way out, which means the normal node form saves the result, a slide
 * can still be edited by hand, and a browser where this never ran still shows
 * a working field.
 */
(function (Drupal, once) {
  'use strict';

  var AMBER = '#ffc400';
  var COLS = 8;
  var ROWS = 14;
  // Which way the short arrow faces, clockwise, for each side it comes from.
  var ROTATION = {
    left: 0,
    'above-left': 45,
    above: 90,
    'above-right': 135,
    right: 180,
    'below-right': 225,
    below: 270,
    'below-left': 315
  };
  var SIDES = ['left', 'right', 'above', 'below', 'above-left', 'above-right', 'below-left', 'below-right'];

  /**
   * Reads the markers field.
   *
   * A deliberately small reader for the shape this field holds -- a flat list
   * of "- x: 0.5" blocks -- rather than a YAML library on every slide form.
   * Anything it does not recognise is left alone, so a hand-written field is
   * never silently thrown away: it simply produces no markers to draw.
   */
  function parse(text) {
    var markers = [];
    var current = null;
    (text || '').split('\n').forEach(function (line) {
      var start = line.match(/^\s*-\s*(.*)$/);
      if (start) {
        current = {};
        markers.push(current);
        line = start[1];
        if (!line.trim()) {
          return;
        }
      }
      if (!current) {
        return;
      }
      var pair = line.match(/^\s*([A-Za-z]+)\s*:\s*(.+?)\s*$/);
      if (!pair) {
        return;
      }
      var key = pair[1];
      var value = pair[2].replace(/^['"]|['"]$/g, '');
      if (key === 'pointer') {
        current.pointer = value;
      }
      else if (['x', 'y', 'number', 'fromX', 'fromY'].indexOf(key) !== -1) {
        current[key] = parseFloat(value);
      }
    });
    return markers.filter(function (m) {
      return typeof m.x === 'number' && typeof m.y === 'number' && !isNaN(m.x) && !isNaN(m.y);
    });
  }

  /**
   * Writes the markers field.
   *
   * A marker keeps whatever it arrived with. The machine ignores `pointer`
   * once a start point is present, so writing both is redundant -- but this
   * field is edited by hand as well, and quietly deleting a line somebody
   * wrote is worse than carrying a key nothing reads.
   */
  function serialise(markers) {
    return markers.map(function (m) {
      var out = '- x: ' + m.x.toFixed(3) + '\n  y: ' + m.y.toFixed(3);
      if (m.number > 0) {
        out += '\n  number: ' + m.number;
      }
      if (m.pointer) {
        out += '\n  pointer: ' + m.pointer;
      }
      if (typeof m.fromX === 'number') {
        out += '\n  fromX: ' + m.fromX.toFixed(3) + '\n  fromY: ' + m.fromY.toFixed(3);
      }
      else if (!m.pointer) {
        out += '\n  pointer: left';
      }
      return out;
    }).join('\n');
  }

  function svgEl(name, attrs) {
    var node = document.createElementNS('http://www.w3.org/2000/svg', name);
    Object.keys(attrs || {}).forEach(function (key) {
      node.setAttribute(key, attrs[key]);
    });
    return node;
  }

  function Editor(form, textarea, imageUrl) {
    this.markers = parse(textarea.value);
    this.textarea = textarea;
    this.selected = -1;
    this.grid = true;
    this.drag = null;
    this.width = 1000;
    this.height = 1000;
    this.build(form, imageUrl);
  }

  Editor.prototype.build = function (form, imageUrl) {
    var self = this;
    var wrapper = document.createElement('div');
    wrapper.className = 'ppuc-marker-editor';

    var stage = document.createElement('div');
    stage.className = 'ppuc-marker-stage';
    var img = document.createElement('img');
    img.src = imageUrl;
    img.alt = Drupal.t('The slide photograph, for placing markers on');
    var svg = svgEl('svg', {preserveAspectRatio: 'none'});
    stage.appendChild(img);
    stage.appendChild(svg);

    var side = document.createElement('div');
    side.className = 'ppuc-marker-side';

    var help = document.createElement('p');
    help.className = 'ppuc-marker-help';
    help.innerHTML = Drupal.t('<strong>Drag</strong> along a shot, from where the ball starts to where it ends. <strong>Click</strong> to mark a spot instead.');

    var tools = document.createElement('div');
    tools.className = 'ppuc-marker-tools';
    var gridButton = document.createElement('button');
    gridButton.type = 'button';
    gridButton.textContent = Drupal.t('Hide grid');
    var clearButton = document.createElement('button');
    clearButton.type = 'button';
    clearButton.className = 'ppuc-marker-danger';
    clearButton.textContent = Drupal.t('Clear all');
    tools.appendChild(gridButton);
    tools.appendChild(clearButton);

    var list = document.createElement('ol');
    list.className = 'ppuc-marker-list';

    side.appendChild(help);
    side.appendChild(tools);
    side.appendChild(list);
    wrapper.appendChild(stage);
    wrapper.appendChild(side);

    var field = textareaWrapper(this.textarea);
    field.parentNode.insertBefore(wrapper, field);

    this.svg = svg;
    this.stage = stage;
    this.list = list;

    img.addEventListener('load', function () {
      self.width = img.naturalWidth || 1000;
      self.height = img.naturalHeight || 1000;
      svg.setAttribute('viewBox', '0 0 ' + self.width + ' ' + self.height);
      self.draw();
    });

    gridButton.addEventListener('click', function () {
      self.grid = !self.grid;
      gridButton.textContent = self.grid ? Drupal.t('Hide grid') : Drupal.t('Show grid');
      self.draw();
    });
    clearButton.addEventListener('click', function () {
      self.markers = [];
      self.selected = -1;
      self.sync();
    });

    stage.addEventListener('pointerdown', function (event) {
      var at = self.at(event);
      self.drag = {fromX: at.x, fromY: at.y, toX: at.x, toY: at.y, moved: false};
      stage.setPointerCapture(event.pointerId);
    });
    stage.addEventListener('pointermove', function (event) {
      if (!self.drag) {
        return;
      }
      var at = self.at(event);
      self.drag.toX = at.x;
      self.drag.toY = at.y;
      // A short drag is a click with a shaky hand, not a shot.
      if (Math.abs(at.x - self.drag.fromX) > 0.02 || Math.abs(at.y - self.drag.fromY) > 0.012) {
        self.drag.moved = true;
      }
      self.draw();
    });
    stage.addEventListener('pointerup', function (event) {
      if (!self.drag) {
        return;
      }
      var at = self.at(event);
      var marker = {x: round(at.x), y: round(at.y), number: self.nextNumber()};
      if (self.drag.moved) {
        marker.fromX = round(self.drag.fromX);
        marker.fromY = round(self.drag.fromY);
      }
      else {
        marker.pointer = at.x > 0.5 ? 'left' : 'right';
      }
      self.markers.push(marker);
      self.selected = self.markers.length - 1;
      self.drag = null;
      self.sync();
    });
    stage.addEventListener('pointercancel', function () {
      self.drag = null;
      self.draw();
    });

    // Someone may prefer to type. Take whatever they typed as the new truth.
    this.textarea.addEventListener('change', function () {
      self.markers = parse(self.textarea.value);
      self.selected = -1;
      self.draw();
      self.renderList();
    });

    this.sync();
  };

  function textareaWrapper(textarea) {
    var node = textarea;
    while (node && node.parentNode && !node.classList.contains('js-form-item')) {
      node = node.parentNode;
    }
    return node || textarea;
  }

  function round(value) {
    return Math.round(Math.min(1, Math.max(0, value)) * 1000) / 1000;
  }

  Editor.prototype.at = function (event) {
    var rect = this.stage.getBoundingClientRect();
    return {
      x: Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width)),
      y: Math.min(1, Math.max(0, (event.clientY - rect.top) / rect.height))
    };
  };

  Editor.prototype.nextNumber = function () {
    return this.markers.reduce(function (max, m) {
      return m.number > max ? m.number : max;
    }, 0) + 1;
  };

  // The arrow PPUC draws: a head sized from the marker, on a shaft as long as
  // the run, with its tip at (tipX, tipY).
  Editor.prototype.arrow = function (tipX, tipY, angle, length, head, half) {
    head = Math.min(head, length);
    var group = svgEl('g', {transform: 'rotate(' + angle + ' ' + tipX + ' ' + tipY + ')'});
    if (length > head) {
      group.appendChild(svgEl('rect', {
        x: tipX - length, y: tipY - half, width: length - head, height: half * 2, fill: AMBER
      }));
    }
    group.appendChild(svgEl('polygon', {
      points: [
        tipX + ',' + tipY,
        (tipX - head) + ',' + (tipY - head * 0.55),
        (tipX - head) + ',' + (tipY + head * 0.55)
      ].join(' '),
      fill: AMBER
    }));
    return group;
  };

  Editor.prototype.draw = function () {
    var self = this;
    var W = this.width;
    var H = this.height;
    while (this.svg.firstChild) {
      this.svg.removeChild(this.svg.firstChild);
    }

    if (this.grid) {
      var cw = W / COLS;
      var ch = H / ROWS;
      var i;
      var j;
      for (i = 1; i < COLS; i++) {
        this.svg.appendChild(svgEl('line', {x1: i * cw, y1: 0, x2: i * cw, y2: H, stroke: '#fff', 'stroke-opacity': '.4', 'stroke-width': '2'}));
      }
      for (j = 1; j < ROWS; j++) {
        this.svg.appendChild(svgEl('line', {x1: 0, y1: j * ch, x2: W, y2: j * ch, stroke: '#fff', 'stroke-opacity': '.4', 'stroke-width': '2'}));
      }
      for (i = 0; i < COLS; i++) {
        for (j = 0; j < ROWS; j++) {
          var label = svgEl('text', {
            x: i * cw + 8, y: j * ch + 30, fill: '#fff', 'fill-opacity': '.7',
            'font-size': Math.max(18, W / 56), 'font-family': 'monospace'
          });
          label.textContent = String.fromCharCode(65 + i) + (j + 1);
          this.svg.appendChild(label);
        }
      }
    }

    var unit = Math.min(W, H);
    var radius = Math.max(14, unit * 0.035);
    var shortLength = Math.max(30, unit * 0.11);
    var head = shortLength * 0.42;
    var half = Math.max(2, shortLength * 0.07);

    this.markers.forEach(function (marker, index) {
      var cx = marker.x * W;
      var cy = marker.y * H;
      var gap = (marker.number > 0 ? radius : 0) + 10;
      var group = svgEl('g', {});

      if (typeof marker.fromX === 'number') {
        var dx = (marker.x - marker.fromX) * W;
        var dy = (marker.y - marker.fromY) * H;
        var distance = Math.sqrt(dx * dx + dy * dy);
        var angle = Math.atan2(dy, dx) * 180 / Math.PI;
        var radians = angle * Math.PI / 180;
        group.appendChild(self.arrow(
          cx - Math.cos(radians) * gap,
          cy - Math.sin(radians) * gap,
          angle, Math.max(distance - gap, head), head, half
        ));
      }
      else {
        var rotation = ROTATION[marker.pointer] || 0;
        var back = (rotation + 180) * Math.PI / 180;
        group.appendChild(self.arrow(
          cx + Math.cos(back) * gap, cy + Math.sin(back) * gap, rotation, shortLength, head, half
        ));
      }

      if (marker.number > 0) {
        group.appendChild(svgEl('circle', {cx: cx, cy: cy, r: radius, fill: AMBER}));
        var text = svgEl('text', {
          x: cx, y: cy + radius * 0.36, 'text-anchor': 'middle', fill: '#191400',
          'font-size': radius * 1.15, 'font-weight': '600', 'font-family': 'monospace'
        });
        text.textContent = marker.number;
        group.appendChild(text);
      }
      if (index === self.selected) {
        group.appendChild(svgEl('circle', {
          cx: cx, cy: cy, r: radius + 14, fill: 'none', stroke: '#fff',
          'stroke-width': '4', 'stroke-dasharray': '10 8'
        }));
      }
      self.svg.appendChild(group);
    });

    if (this.drag && this.drag.moved) {
      this.svg.appendChild(svgEl('line', {
        x1: this.drag.fromX * W, y1: this.drag.fromY * H,
        x2: this.drag.toX * W, y2: this.drag.toY * H,
        stroke: '#fff', 'stroke-width': '6', 'stroke-dasharray': '14 10'
      }));
    }
  };

  Editor.prototype.renderList = function () {
    var self = this;
    this.list.innerHTML = '';
    if (!this.markers.length) {
      var empty = document.createElement('li');
      empty.className = 'ppuc-marker-empty';
      empty.textContent = Drupal.t('Nothing placed yet.');
      this.list.appendChild(empty);
      return;
    }

    this.markers.forEach(function (marker, index) {
      var item = document.createElement('li');
      if (index === self.selected) {
        item.classList.add('is-selected');
      }

      var badge = document.createElement('span');
      badge.className = 'ppuc-marker-badge' + (marker.number > 0 ? '' : ' is-plain');
      badge.textContent = marker.number > 0 ? marker.number : '→';
      item.appendChild(badge);

      var body = document.createElement('div');
      var coords = document.createElement('div');
      coords.className = 'ppuc-marker-coords';
      coords.textContent = marker.x.toFixed(3) + ', ' + marker.y.toFixed(3)
        + (typeof marker.fromX === 'number'
          ? '  ← ' + marker.fromX.toFixed(3) + ', ' + marker.fromY.toFixed(3)
          : '');
      body.appendChild(coords);

      var actions = document.createElement('div');
      actions.className = 'ppuc-marker-actions';

      if (typeof marker.fromX === 'number') {
        var kind = document.createElement('span');
        kind.className = 'ppuc-marker-kind';
        kind.textContent = Drupal.t('shot');
        actions.appendChild(kind);
        actions.appendChild(button(Drupal.t('make it a spot'), function () {
          delete marker.fromX;
          delete marker.fromY;
          marker.pointer = marker.x > 0.5 ? 'left' : 'right';
          self.selected = index;
          self.sync();
        }));
      }
      else {
        SIDES.forEach(function (side) {
          var initials = side.split('-').map(function (word) {
            return word.charAt(0).toUpperCase();
          }).join('');
          var control = button(initials, function () {
            marker.pointer = side;
            self.selected = index;
            self.sync();
          });
          control.title = Drupal.t('Arrow comes from @side', {'@side': side});
          if (marker.pointer === side) {
            control.setAttribute('aria-pressed', 'true');
          }
          actions.appendChild(control);
        });
      }

      actions.appendChild(button(marker.number > 0 ? Drupal.t('no.') : Drupal.t('+no.'), function () {
        marker.number = marker.number > 0 ? 0 : self.nextNumber();
        self.selected = index;
        self.sync();
      }));
      var remove = button(Drupal.t('remove'), function () {
        self.markers.splice(index, 1);
        self.selected = -1;
        self.sync();
      });
      remove.classList.add('ppuc-marker-danger');
      actions.appendChild(remove);

      body.appendChild(actions);
      item.appendChild(body);
      self.list.appendChild(item);
    });
  };

  function button(label, onClick) {
    var control = document.createElement('button');
    control.type = 'button';
    control.textContent = label;
    control.addEventListener('click', onClick);
    return control;
  }

  Editor.prototype.sync = function () {
    this.draw();
    this.renderList();
    this.textarea.value = serialise(this.markers);
  };

  Drupal.behaviors.ppucMarkerEditor = {
    attach: function (context) {
      once('ppuc-marker-editor', '.ppuc-slide-form', context).forEach(function (form) {
        // By name rather than by a class of our own: the field uses a
        // text_format widget, which does not pass attributes through to the
        // textarea it wraps, and the name is what the form submits anyway.
        var textarea = form.querySelector('textarea[name^="field_slide_markers["]');
        if (!textarea) {
          return;
        }
        var imageUrl = form.getAttribute('data-ppuc-slide-image');
        if (!imageUrl) {
          // No photograph saved yet, so there is nothing to place markers on.
          // Say so once, where the editor would have been.
          var note = document.createElement('p');
          note.className = 'ppuc-marker-note';
          note.textContent = Drupal.t('Add a photograph and save the slide, then come back here to draw the markers on it.');
          var wrapper = textareaWrapper(textarea);
          wrapper.parentNode.insertBefore(note, wrapper);
          return;
        }
        // eslint-disable-next-line no-new
        new Editor(form, textarea, imageUrl);
      });
    }
  };
})(Drupal, once);
