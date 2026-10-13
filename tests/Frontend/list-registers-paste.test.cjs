const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const viewPath = path.join(__dirname, '../../resources/views/filament/resources/register-resource/pages/list-registers.blade.php');
const view = fs.readFileSync(viewPath, 'utf8');
const pasteExpression = view.match(/x-on:paste\.capture="([\s\S]*?)"\s*>/)[1];

class InputElement {
    constructor(model, value = '') {
        this.model = model;
        this.value = value;
        this.events = [];
        this.selectionStart = 0;
        this.selectionEnd = value.length;
    }

    getAttribute(name) {
        return name === 'wire:model.live.debounce.500ms' ? this.model : null;
    }

    dispatchEvent(event) {
        this.events.push(event.type);
    }

    setRangeText(text, start, end) {
        this.value = `${this.value.slice(0, start)}${text}${this.value.slice(end)}`;
        this.selectionStart = this.selectionEnd = start + text.length;
    }
}

class BrowserEvent {
    constructor(type) {
        this.type = type;
    }
}

function paste(input, text) {
    let prevented = false;
    const event = {
        target: input,
        clipboardData: { getData: () => text },
        preventDefault: () => { prevented = true; },
    };

    new Function('$event', 'HTMLInputElement', 'Event', pasteExpression)(event, InputElement, BrowserEvent);

    return prevented;
}

const plateSearchInput = new InputElement('tableSearch');
assert.equal(paste(plateSearchInput, 'ABC1234\nDEF5678\nGHI9012'), true);
assert.equal(plateSearchInput.value, 'ABC1234, DEF5678, GHI9012');
assert.deepEqual(plateSearchInput.events, ['input']);

const selectedTextInput = new InputElement('tableSearch', 'prefix old suffix');
selectedTextInput.selectionStart = 7;
selectedTextInput.selectionEnd = 10;
assert.equal(paste(selectedTextInput, 'ABC1234\r\nDEF5678'), true);
assert.equal(selectedTextInput.value, 'prefix ABC1234, DEF5678 suffix');
assert.deepEqual(selectedTextInput.events, ['input']);

const ordinaryPlatePasteInput = new InputElement('tableSearch');
assert.equal(paste(ordinaryPlatePasteInput, 'some multiline\nsearch text'), false);
assert.equal(ordinaryPlatePasteInput.value, '');
assert.deepEqual(ordinaryPlatePasteInput.events, []);

const ordinaryDelimitedPasteInput = new InputElement('tableSearch');
assert.equal(paste(ordinaryDelimitedPasteInput, 'ABC1234, DEF5678'), false);
assert.equal(ordinaryDelimitedPasteInput.value, '');
assert.deepEqual(ordinaryDelimitedPasteInput.events, []);

const otherInput = new InputElement('tableColumnSearches.vehicle_plate');
assert.equal(paste(otherInput, 'ABC1234\nDEF5678'), false);
assert.equal(otherInput.value, '');
assert.deepEqual(otherInput.events, []);
