import { Elements, State } from './config.js';

export function closeCmdMenu() {
    if (Elements.cmdMenu) Elements.cmdMenu.style.display = 'none';
    State.acTarget = null;
    State.acSelectedIndex = 0;
}

export function highlightCmdMenuItem() {
    if (!Elements.cmdMenu) return;
    const items = Array.from(Elements.cmdMenu.children).filter(li => li.style.display !== 'none');
    Array.from(Elements.cmdMenu.children).forEach(li => li.classList.remove('active'));
    if (items.length > 0) {
        if (State.acSelectedIndex >= items.length) State.acSelectedIndex = 0;
        if (State.acSelectedIndex < 0) State.acSelectedIndex = items.length - 1;
        items[State.acSelectedIndex].classList.add('active');
        items[State.acSelectedIndex].scrollIntoView({ block: 'nearest' });
    }
}

export function initAutocomplete(updateCalcFn) {
    if (Elements.cmdMenu) {
        Elements.cmdMenu.addEventListener('click', (e) => {
            if (e.target.tagName === 'LI' && State.acTarget) {
                const valToInsert = e.target.getAttribute('data-val') + '] ';
                const before = State.acTarget.value.substring(0, State.acCursorPos);
                const after = State.acTarget.value.substring(State.acTarget.selectionStart);
                State.acTarget.value = before + valToInsert + after;
                State.acTarget.selectionStart = State.acTarget.selectionEnd = State.acCursorPos + valToInsert.length;
                State.acTarget.focus();
                closeCmdMenu();
                updateCalcFn();
            }
        });

        document.addEventListener('click', (e) => {
            if (Elements.cmdMenu.style.display === 'block' && e.target !== Elements.cmdMenu && !Elements.cmdMenu.contains(e.target)) {
                closeCmdMenu();
            }
        });
    }
}

export function setupAutocompleteEditor(readInput, autoResizeFn, updateCalcFn) {
    readInput.addEventListener('input', (e) => {
        autoResizeFn(e);
        updateCalcFn();
        if (!Elements.cmdMenu) return;
        const val = readInput.value;
        const pos = readInput.selectionStart;

        if (Elements.cmdMenu.style.display === 'block') {
            if (pos < State.acCursorPos) {
                closeCmdMenu();
            } else {
                const query = val.substring(State.acCursorPos, pos).toLowerCase();
                let hasMatch = false;
                Array.from(Elements.cmdMenu.children).forEach(li => {
                    const dataVal = li.getAttribute('data-val').toLowerCase();
                    if (dataVal.includes(query)) {
                        li.style.display = 'block';
                        hasMatch = true;
                    } else {
                        li.style.display = 'none';
                    }
                });
                if (!hasMatch) closeCmdMenu();
                else {
                    State.acSelectedIndex = 0;
                    highlightCmdMenuItem();
                }
            }
        }

        if (val.substring(pos - 1, pos) === '[') {
            const rect = readInput.getBoundingClientRect();
            Elements.cmdMenu.style.display = 'block';
            Elements.cmdMenu.style.top = (rect.top + 30) + 'px'; 
            Elements.cmdMenu.style.left = Math.min(rect.left + 20, window.innerWidth - 250) + 'px';
            State.acTarget = readInput;
            State.acCursorPos = pos;
            
            Array.from(Elements.cmdMenu.children).forEach(li => li.style.display = 'block');
            State.acSelectedIndex = 0;
            highlightCmdMenuItem();
        }
    });

    readInput.addEventListener('keydown', (e) => {
        if (Elements.cmdMenu && Elements.cmdMenu.style.display === 'block') {
            const items = Array.from(Elements.cmdMenu.children).filter(li => li.style.display !== 'none');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                State.acSelectedIndex++;
                highlightCmdMenuItem();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                State.acSelectedIndex--;
                highlightCmdMenuItem();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                if (items.length > 0) {
                    const valToInsert = items[State.acSelectedIndex].getAttribute('data-val') + '] ';
                    const before = State.acTarget.value.substring(0, State.acCursorPos);
                    const after = State.acTarget.value.substring(State.acTarget.selectionStart);
                    State.acTarget.value = before + valToInsert + after;
                    State.acTarget.selectionStart = State.acTarget.selectionEnd = State.acCursorPos + valToInsert.length;
                    closeCmdMenu();
                    autoResizeFn({target: State.acTarget});
                    updateCalcFn();
                }
            } else if (e.key === 'Escape') {
                closeCmdMenu();
            }
        }

        // Atomic Deletion for [TAG] (Instruction Tags)
        if ((e.key === 'Backspace' || e.key === 'Delete') && readInput.selectionStart === readInput.selectionEnd) {
            const val = readInput.value;
            const pos = readInput.selectionStart;
            
            let openIdx = -1;
            
            if (e.key === 'Backspace') {
                openIdx = val.lastIndexOf('[', pos - 1);
            } else if (e.key === 'Delete') {
                openIdx = val.lastIndexOf('[', pos);
                if (openIdx === -1 || openIdx > pos) {
                    openIdx = val.indexOf('[', pos);
                    if (openIdx !== pos) openIdx = -1;
                }
            }

            if (openIdx !== -1) {
                let closeIdx = val.indexOf(']', openIdx);
                let isInsideOrTouching = false;
                
                if (e.key === 'Backspace' && closeIdx !== -1 && closeIdx >= pos - 1 && openIdx < pos) {
                    isInsideOrTouching = true;
                } else if (e.key === 'Delete' && closeIdx !== -1 && closeIdx >= pos && openIdx <= pos) {
                    isInsideOrTouching = true;
                }

                if (isInsideOrTouching && (closeIdx - openIdx < 25)) {
                    e.preventDefault();
                    readInput.value = val.substring(0, openIdx) + val.substring(closeIdx + 1);
                    readInput.selectionStart = readInput.selectionEnd = openIdx;
                    closeCmdMenu();
                    autoResizeFn({target: readInput});
                    updateCalcFn();
                }
            }
        }
    });
}
