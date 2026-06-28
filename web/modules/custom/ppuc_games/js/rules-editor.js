(function (Drupal, once) {
  const COLOUR_HANDLER = 210;
  const COLOUR_PARAM = 260;
  const COLOUR_STATE = 120;
  const COLOUR_GROUP = 160;
  const COLOUR_ACTION = 20;
  const COLOUR_COMMENT = 60;

  function block(type) {
    return {kind: 'block', type};
  }

  function numberInput(name, label, fallback) {
    return {name, label, fallback, check: 'Number'};
  }

  function stringInput(name, label, fallback) {
    return {name, label, fallback, check: 'String'};
  }

  function luaOrder(generator, name) {
    const key = `ORDER_${name}`;
    return typeof generator[key] === 'number' ? generator[key] : generator.ORDER_NONE;
  }

  function luaCallOrder(generator) {
    return typeof generator.ORDER_HIGH === 'number' ? generator.ORDER_HIGH : luaOrder(generator, 'NONE');
  }

  function addHandlerBlock(Blockly, lua, type, handlerName, args, label) {
    Blockly.Blocks[type] = {
      init() {
        this.appendDummyInput().appendField(label);
        this.appendStatementInput('DO');
        this.setColour(COLOUR_HANDLER);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        const body = generator.statementToCode(block, 'DO');
        return `function ppuc.${handlerName}(${args})\n${body}end\n`;
      };
    }
  }

  function addParamBlock(Blockly, lua, type, label, code, check) {
    Blockly.Blocks[type] = {
      init() {
        this.appendDummyInput().appendField(label);
        this.setOutput(true, check);
        this.setColour(COLOUR_PARAM);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        return [code, luaOrder(generator, 'NONE')];
      };
    }
  }

  function addFunctionValueBlock(Blockly, lua, type, label, name, inputs, check, colour) {
    Blockly.Blocks[type] = {
      init() {
        if (inputs.length === 0) {
          this.appendDummyInput().appendField(label);
        }
        inputs.forEach((input, index) => {
          const valueInput = this.appendValueInput(input.name).setCheck(input.check);
          if (index === 0) {
            valueInput.appendField(label);
          }
          if (input.label) {
            valueInput.appendField(input.label);
          }
        });
        this.setOutput(true, check);
        this.setColour(colour);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        const values = inputs.map((input) => generator.valueToCode(block, input.name, luaOrder(generator, 'NONE')) || input.fallback);
        return [`ppuc.${name}(${values.join(', ')})`, luaCallOrder(generator)];
      };
    }
  }

  function addFunctionStatementBlock(Blockly, lua, type, label, name, inputs, colour) {
    Blockly.Blocks[type] = {
      init() {
        if (inputs.length === 0) {
          this.appendDummyInput().appendField(label);
        }
        inputs.forEach((input, index) => {
          const valueInput = this.appendValueInput(input.name).setCheck(input.check);
          if (index === 0) {
            valueInput.appendField(label);
          }
          if (input.label) {
            valueInput.appendField(input.label);
          }
        });
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(colour);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        const values = inputs.map((input) => generator.valueToCode(block, input.name, luaOrder(generator, 'NONE')) || input.fallback);
        return `ppuc.${name}(${values.join(', ')})\n`;
      };
    }
  }

  function addChangedNumberStateBlock(Blockly, lua, type, label, onLabel, offLabel, colour) {
    Blockly.Blocks[type] = {
      init() {
        this.appendValueInput('NUMBER')
          .setCheck('Number')
          .appendField(label);
        this.appendDummyInput()
          .appendField('and got')
          .appendField(new Blockly.FieldDropdown([
            [onLabel, '1'],
            [offLabel, '0'],
          ]), 'STATE');
        this.setOutput(true, 'Boolean');
        this.setColour(colour);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        const number = generator.valueToCode(block, 'NUMBER', luaOrder(generator, 'NONE')) || '0';
        const state = block.getFieldValue('STATE') || '1';
        return [`(number == ${number} and state == ${state})`, luaOrder(generator, 'AND')];
      };
    }
  }

  function addChangedValueBlock(Blockly, lua, type, label, variableName, colour) {
    Blockly.Blocks[type] = {
      init() {
        this.appendValueInput('VALUE')
          .setCheck('Number')
          .appendField(label);
        this.setOutput(true, 'Boolean');
        this.setColour(colour);
      },
    };
    if (lua) {
      lua.forBlock[type] = function (block, generator) {
        const value = generator.valueToCode(block, 'VALUE', luaOrder(generator, 'NONE')) || '0';
        return [`(${variableName} == ${value})`, luaOrder(generator, 'RELATIONAL')];
      };
    }
  }

  function registerPpucBlocks() {
    if (!window.Blockly || window.Blockly.Blocks.ppuc_on_switch_changed) {
      return;
    }

    const Blockly = window.Blockly;
    const lua = window.luaGenerator || window.Lua || window.Blockly.Lua;

    Blockly.Blocks.ppuc_on_switch_changed = {
      init() {
        this.appendDummyInput().appendField('when switch changed');
        this.appendStatementInput('DO');
        this.setColour(COLOUR_HANDLER);
      },
    };
    Blockly.Blocks.ppuc_switch_event_matches = {
      init() {
        this.appendValueInput('NUMBER')
          .setCheck('Number')
          .appendField('changed switch has number');
        this.appendDummyInput()
          .appendField('and got')
          .appendField(new Blockly.FieldDropdown([
            ['closed', '1'],
            ['opened', '0'],
          ]), 'STATE');
        this.setOutput(true, 'Boolean');
        this.setColour(COLOUR_PARAM);
      },
    };
    Blockly.Blocks.ppuc_lamp_state = {
      init() {
        this.appendValueInput('NUMBER').setCheck('Number').appendField('lamp on');
        this.setOutput(true, 'Boolean');
        this.setColour(120);
      },
    };
    Blockly.Blocks.ppuc_pup_trigger = {
      init() {
        this.appendValueInput('SOURCE').setCheck('String').appendField('send PUP trigger');
        this.appendValueInput('ID').setCheck('Number').appendField('id');
        this.appendValueInput('VALUE').setCheck('Number').appendField('value');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(20);
      },
    };
    Blockly.Blocks.ppuc_speech = {
      init() {
        this.appendValueInput('TEXT').setCheck('String').appendField('speech');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(20);
      },
    };
    Blockly.Blocks.ppuc_effect_trigger = {
      init() {
        this.appendValueInput('ID').setCheck(['Number', 'String']).appendField('trigger effect');
        this.appendValueInput('VALUE').setCheck('Number').appendField('value');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(20);
      },
    };
    Blockly.Blocks.ppuc_comment = {
      init() {
        const TextField = Blockly.FieldMultilineInput || Blockly.FieldTextInput;
        this.appendDummyInput()
          .appendField('comment')
          .appendField(new TextField(''), 'TEXT');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(COLOUR_COMMENT);
      },
    };
    Blockly.Blocks.ppuc_pulse_coil = {
      init() {
        this.appendValueInput('NUMBER').setCheck('Number').appendField('activate coil');
        this.appendValueInput('MS').setCheck('Number').appendField('ms');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(20);
      },
    };
    Blockly.Blocks.ppuc_after = {
      init() {
        this.appendValueInput('MS').setCheck('Number').appendField('after');
        this.appendDummyInput().appendField('ms');
        this.appendStatementInput('DO').appendField('do');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(COLOUR_ACTION);
      },
    };
    Blockly.Blocks.ppuc_cooldown = {
      init() {
        this.appendValueInput('DURATION').setCheck('Number').appendField('only once every');
        this.appendDummyInput().appendField('ms');
        this.appendValueInput('NAME').setCheck('String').appendField('for');
        this.setOutput(true, 'Boolean');
        this.setColour(COLOUR_GROUP);
      },
    };
    Blockly.Blocks.ppuc_send_switch_to_cpu = {
      init() {
        this.appendValueInput('NUMBER').setCheck('Number').appendField('send switch to CPU');
        this.appendDummyInput()
          .appendField('as')
          .appendField(new Blockly.FieldDropdown([
            ['closed', '1'],
            ['opened', '0'],
          ]), 'STATE');
        this.setPreviousStatement(true);
        this.setNextStatement(true);
        this.setColour(COLOUR_ACTION);
      },
    };

    if (!lua) {
      return;
    }

    lua.forBlock = lua.forBlock || {};
    lua.forBlock.ppuc_on_switch_changed = function (block, generator) {
      const body = generator.statementToCode(block, 'DO');
      return `function ppuc.onSwitchChanged(number, state)\n${body}end\n`;
    };
    lua.forBlock.ppuc_switch_event_matches = function (block, generator) {
      const number = generator.valueToCode(block, 'NUMBER', luaOrder(generator, 'NONE')) || '0';
      const state = block.getFieldValue('STATE') || '1';
      return [`(number == ${number} and state == ${state})`, luaOrder(generator, 'AND')];
    };
    lua.forBlock.ppuc_lamp_state = function (block, generator) {
      const number = generator.valueToCode(block, 'NUMBER', luaOrder(generator, 'NONE')) || '0';
      return [`ppuc.lampState(${number})`, luaCallOrder(generator)];
    };
    lua.forBlock.ppuc_pup_trigger = function (block, generator) {
      const source = generator.valueToCode(block, 'SOURCE', luaOrder(generator, 'NONE')) || '"P"';
      const id = generator.valueToCode(block, 'ID', luaOrder(generator, 'NONE')) || '0';
      const value = generator.valueToCode(block, 'VALUE', luaOrder(generator, 'NONE')) || '1';
      return `ppuc.pupTrigger(${source}, ${id}, ${value})\n`;
    };
    lua.forBlock.ppuc_speech = function (block, generator) {
      const text = generator.valueToCode(block, 'TEXT', luaOrder(generator, 'NONE')) || '""';
      return `ppuc.speech(${text})\n`;
    };
    lua.forBlock.ppuc_effect_trigger = function (block, generator) {
      const id = generator.valueToCode(block, 'ID', luaOrder(generator, 'NONE')) || '0';
      const value = generator.valueToCode(block, 'VALUE', luaOrder(generator, 'NONE')) || '1';
      return `ppuc.effectTrigger(${id}, ${value})\n`;
    };
    lua.forBlock.ppuc_comment = function (block) {
      const text = block.getFieldValue('TEXT') || '';
      if (!text.trim()) {
        return '--\n';
      }
      return text.split(/\r?\n/).map((line) => `-- ${line}`).join('\n') + '\n';
    };
    lua.forBlock.ppuc_pulse_coil = function (block, generator) {
      const number = generator.valueToCode(block, 'NUMBER', luaOrder(generator, 'NONE')) || '0';
      const ms = generator.valueToCode(block, 'MS', luaOrder(generator, 'NONE')) || '120';
      return `ppuc.pulseCoil(${number}, ${ms})\n`;
    };
    lua.forBlock.ppuc_after = function (block, generator) {
      const ms = generator.valueToCode(block, 'MS', luaOrder(generator, 'NONE')) || '0';
      const body = generator.statementToCode(block, 'DO');
      return `ppuc.after(${ms}, function()\n${body}end)\n`;
    };
    lua.forBlock.ppuc_cooldown = function (block, generator) {
      const name = generator.valueToCode(block, 'NAME', luaOrder(generator, 'NONE')) || '""';
      const duration = generator.valueToCode(block, 'DURATION', luaOrder(generator, 'NONE')) || '0';
      return [`ppuc.onlyOnceEvery(${name}, ${duration})`, luaCallOrder(generator)];
    };
    lua.forBlock.ppuc_send_switch_to_cpu = function (block, generator) {
      const number = generator.valueToCode(block, 'NUMBER', luaOrder(generator, 'NONE')) || '0';
      const state = block.getFieldValue('STATE') || '1';
      return `ppuc.sendSwitchToCpu(${number}, ${state})\n`;
    };

    addChangedNumberStateBlock(Blockly, lua, 'ppuc_lamp_event_matches', 'changed lamp has number', 'turned on', 'turned off', COLOUR_PARAM);
    addChangedNumberStateBlock(Blockly, lua, 'ppuc_coil_event_matches', 'changed coil has number', 'turned on', 'turned off', COLOUR_PARAM);
    addChangedValueBlock(Blockly, lua, 'ppuc_ball_changed_to', 'changed ball is', 'ball', COLOUR_PARAM);
    addChangedValueBlock(Blockly, lua, 'ppuc_player_changed_to', 'changed player is', 'player', COLOUR_PARAM);
    addParamBlock(Blockly, lua, 'ppuc_rules_update_running', 'rules update is running', 'true', 'Boolean');

    addHandlerBlock(Blockly, lua, 'ppuc_on_lamp_changed', 'onLampChanged', 'number, state', 'when lamp changed');
    addHandlerBlock(Blockly, lua, 'ppuc_on_coil_changed', 'onCoilChanged', 'number, state', 'when coil changed');
    addHandlerBlock(Blockly, lua, 'ppuc_on_ball_changed', 'onBallChanged', 'ball', 'when ball changed');
    addHandlerBlock(Blockly, lua, 'ppuc_on_player_changed', 'onPlayerChanged', 'player', 'when player changed');
    addHandlerBlock(Blockly, lua, 'ppuc_on_rules_update', 'onRulesUpdate', '', 'when rules update runs');

    addFunctionValueBlock(Blockly, lua, 'ppuc_switch_state', 'switch closed', 'switchState', [numberInput('NUMBER', '', '0')], 'Boolean', COLOUR_STATE);
    addFunctionValueBlock(Blockly, lua, 'ppuc_coil_state', 'coil activated', 'coilState', [numberInput('NUMBER', '', '0')], 'Boolean', COLOUR_STATE);
    addFunctionValueBlock(Blockly, lua, 'ppuc_current_ball', 'current ball', 'currentBall', [], 'Number', COLOUR_STATE);
    addFunctionValueBlock(Blockly, lua, 'ppuc_current_player', 'current player', 'currentPlayer', [], 'Number', COLOUR_STATE);
    addFunctionValueBlock(Blockly, lua, 'ppuc_attract_mode', 'attract mode', 'attractMode', [], 'Boolean', COLOUR_STATE);

    addFunctionValueBlock(Blockly, lua, 'ppuc_switch_group_state', 'switch group state', 'switchGroupState', [stringInput('NAME', '', '""')], 'Boolean', COLOUR_GROUP);
    addFunctionValueBlock(Blockly, lua, 'ppuc_switch_group_closing', 'switch group closing', 'switchGroupClosing', [stringInput('NAME', '', '""')], 'Boolean', COLOUR_GROUP);
    addFunctionValueBlock(Blockly, lua, 'ppuc_switch_group_opening', 'switch group opening', 'switchGroupOpening', [stringInput('NAME', '', '""')], 'Boolean', COLOUR_GROUP);
    addFunctionValueBlock(Blockly, lua, 'ppuc_state_active', 'state active', 'stateActive', [stringInput('NAME', '', '""')], 'Boolean', COLOUR_GROUP);
    addFunctionValueBlock(Blockly, lua, 'ppuc_trigger_history', 'trigger history', 'triggerHistory', [numberInput('ID', 'id', '0'), numberInput('WINDOW', 'window ms', '0')], 'Boolean', COLOUR_GROUP);
    addFunctionValueBlock(Blockly, lua, 'ppuc_trigger_sequence', 'trigger sequence', 'triggerSequence', [numberInput('WINDOW', 'window ms', '0'), numberInput('ID1', 'id 1', '0'), numberInput('ID2', 'id 2', '0'), numberInput('ID3', 'id 3', '0')], 'Boolean', COLOUR_GROUP);
    addFunctionStatementBlock(Blockly, lua, 'ppuc_set_state', 'set state', 'setState', [stringInput('NAME', '', '""'), numberInput('DURATION', 'duration ms', '0')], COLOUR_GROUP);
    addFunctionStatementBlock(Blockly, lua, 'ppuc_clear_state', 'clear state', 'clearState', [stringInput('NAME', '', '""')], COLOUR_GROUP);
    addFunctionStatementBlock(Blockly, lua, 'ppuc_suppress_switch', 'suppress switch', 'suppressSwitch', [numberInput('NUMBER', 'number', '0')], COLOUR_ACTION);
    addFunctionStatementBlock(Blockly, lua, 'ppuc_blink_lamp', 'blink lamp', 'blinkLamp', [numberInput('NUMBER', 'number', '0'), numberInput('ON', 'on ms', '250'), numberInput('OFF', 'off ms', '250')], COLOUR_ACTION);
    addFunctionStatementBlock(Blockly, lua, 'ppuc_stop_blink_lamp', 'stop blink lamp', 'stopBlinkLamp', [numberInput('NUMBER', 'number', '0')], COLOUR_ACTION);
  }

  Drupal.behaviors.ppucRulesEditor = {
    attach(context) {
      once('ppuc-rules-editor', '.ppuc-rules-form', context).forEach((form) => {
        const pageLayout = form.closest('.with-sidebar');
        if (pageLayout) {
          pageLayout.classList.add('ppuc-rules-page-layout');
        }

        const lua = form.querySelector('[name^="field_rules_lua"][name$="[value]"]');
        const blocks = form.querySelector('[name^="field_rules_blocks"][name$="[value]"]');
        const modeInputs = form.querySelectorAll('[name^="field_rules_editor_mode"]');
        const workspaceElement = form.querySelector('[data-ppuc-rules-blockly]');
        const generateButton = form.querySelector('.ppuc-rules-blockly-generate');
        const editLuaButton = form.querySelector('.ppuc-rules-edit-lua');
        const useBlocklyButton = form.querySelector('.ppuc-rules-use-blockly');
        const status = form.querySelector('.ppuc-rules-status');

        if (!lua || !blocks || !workspaceElement) {
          return;
        }

        if (!window.Blockly) {
          workspaceElement.classList.add('is-unavailable');
          workspaceElement.textContent = 'Blockly assets are not installed. Edit Lua directly.';
          if (generateButton) {
            generateButton.hidden = true;
          }
          return;
        }

        try {
          registerPpucBlocks();
          workspaceElement.textContent = '';
          workspaceElement.style.padding = '0';
          let currentMode = getMode();

          const workspace = window.Blockly.inject(workspaceElement, {
            toolbox: {
              kind: 'categoryToolbox',
              contents: [
                {
                  kind: 'category',
                  name: 'Logic',
                  contents: [
                    {kind: 'block', type: 'controls_if'},
                    {kind: 'block', type: 'logic_compare'},
                    {kind: 'block', type: 'logic_operation'},
                    {kind: 'block', type: 'logic_negate'},
                  ],
                },
                {
                  kind: 'category',
                  name: 'Values',
                  contents: [
                    {kind: 'block', type: 'math_number'},
                    {kind: 'block', type: 'text'},
                  ],
                },
                {
                  kind: 'category',
                  name: 'Handlers',
                  contents: [
                    block('ppuc_on_switch_changed'),
                    block('ppuc_on_lamp_changed'),
                    block('ppuc_on_coil_changed'),
                    block('ppuc_on_ball_changed'),
                    block('ppuc_on_player_changed'),
                    block('ppuc_on_rules_update'),
                  ],
                },
                {
                  kind: 'category',
                  name: 'Handler values',
                  contents: [
                    block('ppuc_switch_event_matches'),
                    block('ppuc_lamp_event_matches'),
                    block('ppuc_coil_event_matches'),
                    block('ppuc_ball_changed_to'),
                    block('ppuc_player_changed_to'),
                    block('ppuc_rules_update_running'),
                  ],
                },
                {
                  kind: 'category',
                  name: 'States',
                  contents: [
                    block('ppuc_switch_state'),
                    block('ppuc_lamp_state'),
                    block('ppuc_coil_state'),
                    block('ppuc_current_ball'),
                    block('ppuc_current_player'),
                    block('ppuc_attract_mode'),
                  ],
                },
                {
                  kind: 'category',
                  name: 'Groups and history',
                  contents: [
                    block('ppuc_switch_group_state'),
                    block('ppuc_switch_group_closing'),
                    block('ppuc_switch_group_opening'),
                    block('ppuc_set_state'),
                    block('ppuc_clear_state'),
                    block('ppuc_state_active'),
                    block('ppuc_trigger_history'),
                    block('ppuc_trigger_sequence'),
                    block('ppuc_cooldown'),
                  ],
                },
                {
                  kind: 'category',
                  name: 'Actions',
                  contents: [
                    block('ppuc_comment'),
                    block('ppuc_pup_trigger'),
                    block('ppuc_speech'),
                    block('ppuc_effect_trigger'),
                    block('ppuc_after'),
                    block('ppuc_suppress_switch'),
                    block('ppuc_send_switch_to_cpu'),
                    block('ppuc_pulse_coil'),
                    block('ppuc_blink_lamp'),
                    block('ppuc_stop_blink_lamp'),
                  ],
                },
              ],
            },
          });

          if (blocks.value.trim()) {
            window.Blockly.serialization.workspaces.load(JSON.parse(blocks.value), workspace);
          }

          const editor = window.CodeMirror
            ? window.CodeMirror.fromTextArea(lua, {
              mode: 'lua',
              lineNumbers: true,
              viewportMargin: Infinity,
            })
            : null;

          function setStatus(message) {
            if (status) {
              status.textContent = message || '';
            }
          }

          function getMode() {
            const checked = form.querySelector('[name^="field_rules_editor_mode"]:checked');
            if (checked) {
              return checked.value;
            }
            const select = form.querySelector('select[name^="field_rules_editor_mode"]');
            return select ? select.value : (form.dataset.ppucRulesMode || 'blockly');
          }

          function setModeValue(mode) {
            modeInputs.forEach((input) => {
              if (input.type === 'radio') {
                input.checked = input.value === mode;
              }
              else {
                input.value = mode;
              }
            });
          }

          function generateLua() {
            const generator = window.luaGenerator || window.Lua || window.Blockly.Lua;
            if (!generator || typeof generator.workspaceToCode !== 'function') {
              return;
            }
            const code = generator.workspaceToCode(workspace);
            if (editor) {
              editor.setValue(code);
            }
            else {
              lua.value = code;
            }
          }

          function applyMode(mode) {
            currentMode = mode === 'lua' ? 'lua' : 'blockly';
            setModeValue(currentMode);
            form.dataset.ppucRulesMode = currentMode;
            form.classList.toggle('is-lua-mode', currentMode === 'lua');
            form.classList.toggle('is-blockly-mode', currentMode !== 'lua');
            workspaceElement.hidden = currentMode === 'lua';
            if (generateButton) {
              generateButton.hidden = currentMode === 'lua';
            }
            if (editLuaButton) {
              editLuaButton.hidden = currentMode === 'lua';
            }
            if (useBlocklyButton) {
              useBlocklyButton.hidden = currentMode !== 'lua';
            }
            if (editor) {
              editor.setOption('readOnly', currentMode === 'lua' ? false : 'nocursor');
              editor.refresh();
            }
            else {
              lua.readOnly = currentMode !== 'lua';
            }
            if (currentMode === 'blockly') {
              generateLua();
              setStatus('Lua preview is generated from Blockly.');
            }
            else {
              setStatus('Lua editing is enabled. Blockly is disabled for this rule.');
            }
          }

          workspace.addChangeListener(() => {
            blocks.value = JSON.stringify(window.Blockly.serialization.workspaces.save(workspace));
            if (currentMode === 'blockly') {
              generateLua();
            }
          });

          window.Blockly.svgResize(workspace);
          setTimeout(() => window.Blockly.svgResize(workspace), 0);
          setTimeout(() => window.Blockly.svgResize(workspace), 250);

          if (generateButton) {
            generateButton.addEventListener('click', () => {
              generateLua();
              setStatus('Lua preview updated.');
            });
          }
          if (editLuaButton) {
            editLuaButton.addEventListener('click', () => {
              if (window.confirm('Edit Lua directly for this rule? Blockly will be disabled for this rule.')) {
                applyMode('lua');
              }
            });
          }
          if (useBlocklyButton) {
            useBlocklyButton.addEventListener('click', () => {
              if (window.confirm('Use Blockly for this rule? The Lua editor will be overwritten by Blockly output.')) {
                applyMode('blockly');
              }
            });
          }
          modeInputs.forEach((input) => {
            input.addEventListener('change', () => applyMode(getMode()));
          });
          form.addEventListener('submit', () => {
            if (editor) {
              editor.save();
            }
          });
          applyMode(currentMode);
        }
        catch (error) {
          workspaceElement.classList.add('is-unavailable');
          workspaceElement.textContent = `Blockly failed to initialize: ${error.message}`;
          if (generateButton) {
            generateButton.hidden = true;
          }
          if (window.console) {
            window.console.error(error);
          }
        }
      });
    },
  };
})(Drupal, once);
