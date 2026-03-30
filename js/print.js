import { Elements } from './config.js';

export function setupPrint(saveStoryCallback) {
    if (Elements.btnPrint) {
        Elements.btnPrint.addEventListener('click', async () => {
            const saved = await saveStoryCallback(false);
            if (!saved) {
                console.warn("Could not save before printing, but proceeding with print anyway.");
            }
            
            document.getElementById('print-slug-text').innerText = Elements.metaSlug.value || 'Untitled Story';
            document.getElementById('print-reporter-text').innerText = Elements.metaReporter ? Elements.metaReporter.value : '-';
            document.getElementById('print-department-text').innerText = (Elements.metaDepartment && Elements.metaDepartment.options.length > 0 && Elements.metaDepartment.selectedIndex >= 0 ? Elements.metaDepartment.options[Elements.metaDepartment.selectedIndex].text : '-');
            
            document.querySelectorAll('.script-row').forEach(row => {
                const ta = row.querySelector('.read-input');
                let printDiv = row.querySelector('.print-read-text');
                if (!printDiv) {
                    printDiv = document.createElement('div');
                    printDiv.className = 'print-read-text';
                    ta.parentNode.insertBefore(printDiv, ta.nextSibling);
                }
                printDiv.innerText = ta.value;

                row.querySelectorAll('.cue-block').forEach(cb => {
                    const cueType = cb.querySelector('.cue-type');
                    const cueInput = cb.querySelector('.cue-detail');
                    let printCueDiv = cb.querySelector('.print-cue-text');
                    if (!printCueDiv) {
                        printCueDiv = document.createElement('div');
                        printCueDiv.className = 'print-cue-text';
                        cb.appendChild(printCueDiv);
                    }
                    
                    const typeText = cueType.options.length > 0 && cueType.selectedIndex >= 0 ? cueType.options[cueType.selectedIndex].text : cueType.value;
                    const detailText = cueInput.value || '';
                    
                    if (detailText.trim()) {    
                        printCueDiv.innerHTML = `<strong>[${typeText}]</strong><br/>${detailText.replace(/\n/g, '<br/>')}`;
                    } else {
                        printCueDiv.innerHTML = `<strong>[${typeText}]</strong>`;
                    }
                });
            });
            
            setTimeout(() => {
                window.print();
            }, 100);
        });
    }
}
