import { Elements, Config } from './config.js?v=3';
import { countCharacters, formatTime } from './utils.js?v=3';
import { setupAutocompleteEditor } from './autocomplete.js?v=3';

export function updateCalculations() {
    let totalEstimatedSeconds = 0;
    document.querySelectorAll('.script-row').forEach(row => {
        const textInput = row.querySelector('.read-input').value;
        const chars = countCharacters(textInput);
        const estSec = Math.ceil(chars / (Config.CHARS_PER_SEC || 40));
        row.querySelector('.word-count').innerText = chars + ' chars';
        row.querySelector('.row-time').innerText = estSec;
        totalEstimatedSeconds += estSec;
    });

    if (Elements.totalTimeDisplay) Elements.totalTimeDisplay.innerText = formatTime(totalEstimatedSeconds);
    return totalEstimatedSeconds;
}

export function addNewRow(cuesArray = [], readText = '') {
    if (!Elements.template) return;
    const clone = Elements.template.content.cloneNode(true);
    const row = clone.querySelector('.script-row');
    const readInput = row.querySelector('.read-input');
    readInput.value = readText;
    
    const autoResize = (e) => {
        e.target.style.height = 'auto';
        e.target.style.height = (e.target.scrollHeight) + 'px';
    };
    
    setupAutocompleteEditor(readInput, autoResize, updateCalculations);
    
    const cueBlocksContainer = row.querySelector('.cue-blocks');
    const btnAddCue = row.querySelector('.btn-add-cue');

    function addCueBlock(type = 'CAM', detail = '') {
        const cueTemplate = document.getElementById('cue-template');
        const cueClone = cueTemplate.content.cloneNode(true);
        const cueBlock = cueClone.querySelector('.cue-block');
        
        cueBlock.querySelector('.cue-type').value = type;
        cueBlock.querySelector('.cue-detail').value = detail;
        cueBlock.querySelector('.btn-remove-cue').addEventListener('click', () => {
            cueBlock.remove();
        });
        cueBlocksContainer.appendChild(cueBlock);
    }
    
    btnAddCue.addEventListener('click', () => addCueBlock());
    
    if (typeof cuesArray === 'string') {
        if (cuesArray.trim()) addCueBlock('RAW', cuesArray);
    } else if (Array.isArray(cuesArray) && cuesArray.length > 0) {
        cuesArray.forEach(c => addCueBlock(c.type || 'CAM', c.value || ''));
    } else {
        addCueBlock(); 
    }
    
    row.querySelector('.btn-remove-row').addEventListener('click', () => {
        row.remove();
        updateCalculations();
    });

    Elements.scriptBody.appendChild(clone);
    updateCalculations();
}
