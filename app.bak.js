const WPM_RATE = 3; // 3 words per second ≈ 180 words per minute
let currentStoryId = null;

// DOM Elements
const scriptBody = document.getElementById('script-body');
const btnAddRow = document.getElementById('btn-add-row');
const btnSave = document.getElementById('btn-save');
const btnPrint = document.getElementById('btn-print');
const template = document.getElementById('row-template');

// Metadata Elements
const metaSlug = document.getElementById('meta-slug');
const metaDepartment = document.getElementById('meta-department');
const metaReporter = document.getElementById('meta-reporter');
const metaStatus = document.getElementById('meta-status');
const totalTimeDisplay = document.getElementById('total-time');

// Archive Elements
const sidebar = document.getElementById('archive-sidebar');
const btnArchive = document.getElementById('btn-archive') || document.getElementById('nav-find-story');
const btnCloseArchive = document.getElementById('btn-close-archive');
const searchDept = document.getElementById('search-department');
const searchKw = document.getElementById('search-keyword');
const btnDoSearch = document.getElementById('btn-do-search');
const archiveResults = document.getElementById('archive-results');

// Modal Elements
const modal = document.getElementById('preview-modal');
const btnClosePreview = document.getElementById('btn-close-preview');
const btnLoadPreview = document.getElementById('btn-load-preview');
const btnCancelPreview = document.getElementById('btn-cancel-preview');
const previewTitle = document.getElementById('preview-title');
const previewBody = document.getElementById('preview-body');

let previewingStoryId = null;

// Command Autocomplete Elements
const cmdMenu = document.getElementById('cmd-autocomplete');
let acTarget = null;
let acCursorPos = -1;
let acSelectedIndex = 0;

function closeCmdMenu() {
    if (cmdMenu) cmdMenu.style.display = 'none';
    acTarget = null;
    acSelectedIndex = 0;
}

if (cmdMenu) {
    cmdMenu.addEventListener('click', (e) => {
        if (e.target.tagName === 'LI' && acTarget) {
            const valToInsert = e.target.getAttribute('data-val') + '] ';
            const before = acTarget.value.substring(0, acCursorPos);
            // Replace whatever they typed after [ with the final value
            const after = acTarget.value.substring(acTarget.selectionStart);
            acTarget.value = before + valToInsert + after;
            acTarget.selectionStart = acTarget.selectionEnd = acCursorPos + valToInsert.length;
            acTarget.focus();
            closeCmdMenu();
            updateCalculations();
        }
    });

    document.addEventListener('click', (e) => {
        if (cmdMenu.style.display === 'block' && e.target !== cmdMenu && !cmdMenu.contains(e.target)) {
            closeCmdMenu();
        }
    });
}

function highlightCmdMenuItem() {
    if (!cmdMenu) return;
    const items = Array.from(cmdMenu.children).filter(li => li.style.display !== 'none');
    Array.from(cmdMenu.children).forEach(li => li.classList.remove('active'));
    if (items.length > 0) {
        if (acSelectedIndex >= items.length) acSelectedIndex = 0;
        if (acSelectedIndex < 0) acSelectedIndex = items.length - 1;
        items[acSelectedIndex].classList.add('active');
        items[acSelectedIndex].scrollIntoView({ block: 'nearest' });
    }
}

// Initialize with one row if empty
document.addEventListener('DOMContentLoaded', async () => {
    // 1. Load departments
    try {
        const res = await fetch('api.php?action=get_departments');
        const result = await res.json();
        if (result.success && metaDepartment) {
            metaDepartment.innerHTML = '';
            if (searchDept) searchDept.innerHTML = '<option value="">ทุกสังกัด (All)</option>'; // Reset
            
            result.data.forEach(dept => {
                // Populate editor dropdown
                const opt = document.createElement('option');
                opt.value = dept.id;
                opt.textContent = dept.name;
                metaDepartment.appendChild(opt);
                
                // Populate search dropdown
                if (searchDept) {
                    const sOpt = document.createElement('option');
                    sOpt.value = dept.id;
                    sOpt.textContent = dept.name;
                    searchDept.appendChild(sOpt);
                }
            });
        }
    } catch(e) { console.error("Could not load departments", e); }

    // Load users for datalist
    try {
        const resUsers = await fetch('api.php?action=get_users');
        const resultUsers = await resUsers.json();
        if (resultUsers.success && resultUsers.data) {
            const dataList = document.getElementById('reporter-list');
            if (dataList) {
                resultUsers.data.forEach(u => {
                    const opt = document.createElement('option');
                    opt.value = u.name;
                    dataList.appendChild(opt);
                });
            }
        }
    } catch(e) { console.error("Could not load users", e); }

    // 2. Apply UI Permissions
    applyUIPermissions();

    // Check if ID is in URL (for loading)
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id');
    
    if (id) {
        await loadStory(id);
    } else {
        if (window.currentUser) {
            if (metaDepartment) metaDepartment.value = window.currentUser.departmentId || '';
            if (metaReporter && !metaReporter.value) metaReporter.value = window.currentUser.fullName || '';
        }
        addNewRow();
    }
    
    startAutoSave();
});

// UI Permission Rules
function applyUIPermissions() {
    if (!window.currentUser) return;
    const rId = window.currentUser.roleId;

    // Reporter (1) or Rewriter (4) cannot approve
    if (rId == 1 || rId == 4) {
        if (metaStatus) {
            const approveOpt = metaStatus.querySelector('option[value="APPROVED"]');
            if (approveOpt) approveOpt.disabled = true;
        }
    }

    // Reporter (1) or Editor (2) cannot change departments
    if (rId == 1 || rId == 2) {
        if (metaDepartment) {
            metaDepartment.disabled = true;
        }
    }
}

// Calculate words using Intl.Segmenter for accurate Thai word boundaries
function countWords(str) {
    if (!str.trim()) return 0;
    
    // Use Intl.Segmenter for Thai (and other languages) word segmentation
    if (typeof Intl !== 'undefined' && Intl.Segmenter) {
        const segmenter = new Intl.Segmenter('th', { granularity: 'word' });
        const segments = segmenter.segment(str);
        let wordCount = 0;
        for (const segment of segments) {
            // Count only actual words (ignore punctuation, spaces)
            if (segment.isWordLike) {
                wordCount++;
            }
        }
        return wordCount;
    }
    
    // Fallback for older browsers
    return str.trim().split(/\s+/).length;
}

// Format seconds to MM:SS
function formatTime(totalSeconds) {
    const m = Math.floor(totalSeconds / 60).toString().padStart(2, '0');
    const s = Math.floor(totalSeconds % 60).toString().padStart(2, '0');
    return `${m}:${s}`;
}

// Recalculate timing for everything
function updateCalculations() {
    let totalEstimatedSeconds = 0;
    
    document.querySelectorAll('.script-row').forEach(row => {
        const textInput = row.querySelector('.read-input').value;
        const words = countWords(textInput);
        
        // Time = Words / (Words per second)
        const estSec = Math.ceil(words / WPM_RATE);
        
        row.querySelector('.word-count').innerText = words;
        row.querySelector('.row-time').innerText = estSec;
        
        totalEstimatedSeconds += estSec;
    });

    totalTimeDisplay.innerText = formatTime(totalEstimatedSeconds);
    return totalEstimatedSeconds;
}

// Add a new row
function addNewRow(cuesArray = [], readText = '') {
    const clone = template.content.cloneNode(true);
    const row = clone.querySelector('.script-row');
    
    const readInput = row.querySelector('.read-input');
    readInput.value = readText;
    
    // Auto resize textarea
    const autoResize = (e) => {
        e.target.style.height = 'auto';
        e.target.style.height = (e.target.scrollHeight) + 'px';
    };
    
    readInput.addEventListener('input', (e) => {
        autoResize(e);
        updateCalculations();

        if (!cmdMenu) return;
        const val = readInput.value;
        const pos = readInput.selectionStart;

        if (cmdMenu.style.display === 'block') {
            if (pos < acCursorPos) {
                closeCmdMenu();
            } else {
                const query = val.substring(acCursorPos, pos).toLowerCase();
                let hasMatch = false;
                Array.from(cmdMenu.children).forEach(li => {
                    const dataVal = li.getAttribute('data-val').toLowerCase();
                    if (dataVal.includes(query)) {
                        li.style.display = 'block';
                        hasMatch = true;
                    } else {
                        li.style.display = 'none';
                    }
                });
                if (!hasMatch) {
                    closeCmdMenu();
                } else {
                    acSelectedIndex = 0;
                    highlightCmdMenuItem();
                }
            }
        }

        if (val.substring(pos - 1, pos) === '[') {
            const rect = readInput.getBoundingClientRect();
            cmdMenu.style.display = 'block';
            cmdMenu.style.top = (rect.top + 30) + 'px'; 
            cmdMenu.style.left = Math.min(rect.left + 20, window.innerWidth - 250) + 'px';
            acTarget = readInput;
            acCursorPos = pos;
            
            Array.from(cmdMenu.children).forEach(li => li.style.display = 'block');
            acSelectedIndex = 0;
            highlightCmdMenuItem();
        }
    });

    readInput.addEventListener('keydown', (e) => {
        if (cmdMenu && cmdMenu.style.display === 'block') {
            const items = Array.from(cmdMenu.children).filter(li => li.style.display !== 'none');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                acSelectedIndex++;
                highlightCmdMenuItem();
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                acSelectedIndex--;
                highlightCmdMenuItem();
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                if (items.length > 0) {
                    const valToInsert = items[acSelectedIndex].getAttribute('data-val') + '] ';
                    const before = acTarget.value.substring(0, acCursorPos);
                    const after = acTarget.value.substring(acTarget.selectionStart);
                    acTarget.value = before + valToInsert + after;
                    acTarget.selectionStart = acTarget.selectionEnd = acCursorPos + valToInsert.length;
                    closeCmdMenu();
                    autoResize({target: acTarget});
                    updateCalculations();
                }
            } else if (e.key === 'Escape') {
                closeCmdMenu();
            }
        }
    });
    
    // Setup cue blocks
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
    
    // Populate existing cues
    if (typeof cuesArray === 'string') {
        if (cuesArray.trim()) addCueBlock('RAW', cuesArray);
    } else if (Array.isArray(cuesArray) && cuesArray.length > 0) {
        cuesArray.forEach(c => addCueBlock(c.type || 'CAM', c.value || ''));
    } else {
        addCueBlock(); // default empty cue
    }
    
    row.querySelector('.btn-remove-row').addEventListener('click', () => {
        row.remove();
        updateCalculations();
    });

    scriptBody.appendChild(clone);
    updateCalculations();
}

btnAddRow.addEventListener('click', () => addNewRow());

// Print Story
if (btnPrint) {
    btnPrint.addEventListener('click', async () => {
        // Auto save before printing
        const saved = await saveStory(false);
        if (!saved) {
            console.warn("Could not save before printing, but proceeding with print anyway.");
        }
        
        document.getElementById('print-slug-text').innerText = metaSlug.value || 'Untitled Story';
        document.getElementById('print-reporter-text').innerText = metaReporter ? metaReporter.value : '-';
        document.getElementById('print-department-text').innerText = (metaDepartment && metaDepartment.options.length > 0 && metaDepartment.selectedIndex >= 0 ? metaDepartment.options[metaDepartment.selectedIndex].text : '-');
        
        // Fix textarea print clipping: Browsers cut off <textarea> in print if it spans multiple pages
        document.querySelectorAll('.script-row').forEach(row => {
            const ta = row.querySelector('.read-input');
            let printDiv = row.querySelector('.print-read-text');
            if (!printDiv) {
                printDiv = document.createElement('div');
                printDiv.className = 'print-read-text';
                ta.parentNode.insertBefore(printDiv, ta.nextSibling);
            }
            // Preserve newlines and text
            printDiv.innerText = ta.value;
        });
        
        // Let the DOM update first, then print
        setTimeout(() => {
            window.print();
        }, 100);
    });
}

// Load Story
async function loadStory(id) {
    try {
        const res = await fetch(`api.php?action=get_story&id=${id}`);
        const result = await res.json();
        
        if (result.success) {
            currentStoryId = result.data.id;
            const meta = result.data.metadata;
            
            if (metaSlug) metaSlug.value = meta.slug || '';
            if (metaDepartment) metaDepartment.value = meta.department || '';
            if (metaReporter) metaReporter.value = meta.reporter || '';
            if (metaStatus) metaStatus.value = meta.status || 'DRAFT';
            
            // Re-check permissions based on loaded story vs User scope
            const rId = window.currentUser ? window.currentUser.roleId : null;
            if (rId == 1 || rId == 2) {
                if (metaDepartment.value != window.currentUser.departmentId) {
                    // Lock editing if user loaded a different department's story
                    btnSave.innerText = 'READ ONLY';
                    btnSave.disabled = true;
                    btnAddRow.style.display = 'none';
                    if (metaStatus) metaStatus.disabled = true;
                }
            }
            
            scriptBody.innerHTML = '';
            
            result.data.content.forEach(row => {
                const cues = row.leftColumn.cues || [];
                addNewRow(cues, row.rightColumn.text);
            });
        } else {
            alert('Failed to load story: ' + result.error);
            addNewRow();
        }
    } catch (e) {
        console.error(e);
        alert('Error parsing story data');
    }
}

// Auto Save Timer
let autoSaveInterval = null;
function startAutoSave() {
    if (autoSaveInterval) clearInterval(autoSaveInterval);
    autoSaveInterval = setInterval(async () => {
        console.log("Auto-saving...");
        await saveStory(true);
    }, 5 * 60 * 1000); // 5 minutes
}

// Save story to API
async function saveStory(showFeedback = true) {
    if (!isAutoSave) {
        btnSave.innerText = 'SAVING...';
        btnSave.disabled = false;
        btnSave.innerText = 'SAVE STORY';
    }
}

// ==========================================
// Archive Search Logic
// ==========================================
if (btnArchive) btnArchive.addEventListener('click', (e) => {
    e.preventDefault();
    sidebar.classList.add('open');
    if (window.currentUser) {
        // Default search dept to current user's dept
        searchDept.value = window.currentUser.departmentId;
    }
});

if (btnCloseArchive) btnCloseArchive.addEventListener('click', () => sidebar.classList.remove('open'));

if (btnDoSearch) btnDoSearch.addEventListener('click', async () => {
    const dId = searchDept.value;
    const kw = searchKw.value.trim();
    
    archiveResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">กำลังค้นหา...</div>';
    
    try {
        const res = await fetch(`api.php?action=search_stories&department_id=${dId}&keyword=${encodeURIComponent(kw)}`);
        const result = await res.json();
        
        if (result.success) {
            if (result.data.length === 0) {
                archiveResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">ไม่พบข่าวที่ค้นหา</div>';
                return;
            }
            archiveResults.innerHTML = '';
            result.data.forEach(story => {
                const item = document.createElement('div');
                item.className = 'archive-item';
                let statusColor = '#333';
                if (story.status === 'READY') statusColor = '#2196F3';
                else if (story.status === 'REVIEW') statusColor = '#FF9800';
                else if (story.status === 'APPROVED') statusColor = '#4CAF50';
                
                item.innerHTML = `
                    <div class="archive-item-header">
                        <div class="archive-item-title">${story.slug || 'Untitled Story'}</div>
                        <span class="status-badge" style="background-color: ${statusColor};">${story.status}</span>
                    </div>
                    <div class="archive-item-meta">
                        <div class="meta-row-info">
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg> ${story.department_name || '-'}</span>
                            <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${story.updated_at}</span>
                        </div>
                    </div>
                `;
                item.addEventListener('click', () => openPreviewModal(story.id, story.slug));
                archiveResults.appendChild(item);
            });
        } else {
            archiveResults.innerHTML = `<div style="color:var(--danger); text-align:center;">${result.error}</div>`;
        }
    } catch(e) {
        archiveResults.innerHTML = `<div style="color:var(--danger); text-align:center;">เกิดข้อผิดพลาดในการเชื่อมต่อ</div>`;
    }
});

function escapeHTML(str) {
    if (!str) return '';
    return str.toString().replace(/[&<>'"]/g, tag => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[tag] || tag));
}

async function openPreviewModal(id, title) {
    previewTitle.innerText = title || 'Preview';
    previewBody.innerHTML = '<div style="text-align:center;">Loading...</div>';
    modal.classList.add('open');
    previewingStoryId = id;
    
    try {
        const res = await fetch(`api.php?action=get_story&id=${id}`);
        const result = await res.json();
        if (result.success) {
            previewBody.innerHTML = '';
            if (result.data.content && result.data.content.length > 0) {
                result.data.content.forEach((row, index) => {
                    const b = document.createElement('div');
                    b.className = 'preview-block';
                    
                    // Render cues concisely
                    let cueHtml = '';
                    if (row.leftColumn && row.leftColumn.cues) {
                        row.leftColumn.cues.forEach(c => {
                            cueHtml += `<div class="preview-cue">[${c.type}] ${c.detail}</div>`;
                        });
                    }
                    
                    b.innerHTML = `
                        ${cueHtml}
                        <div style="font-family: var(--font-thai);">${escapeHTML(row.rightColumn.text)}</div>
                    `;
                    previewBody.appendChild(b);
                });
            } else {
                previewBody.innerHTML = '<div style="text-align:center; color:var(--text-secondary);">เนื้อหาว่างเปล่า</div>';
            }
        }
    } catch(e) {
        previewBody.innerHTML = `<div style="color:var(--danger); text-align:center;">เกิดข้อผิดพลาดในการโหลดเนื้อหา</div>`;
    }
}

const closeModal = () => modal.classList.remove('open');
if (btnClosePreview) btnClosePreview.addEventListener('click', closeModal);
if (btnCancelPreview) btnCancelPreview.addEventListener('click', closeModal);
if (btnLoadPreview) btnLoadPreview.addEventListener('click', () => {
    if (previewingStoryId) {
        window.location.href = `index.php?id=${previewingStoryId}`;
    }
});

// Save Story Function
async function saveStory(isAutoSave = false) {
    if (!isAutoSave) {
        btnSave.innerText = 'SAVING...';
        btnSave.disabled = true;
    } else {
        btnSave.innerText = 'AUTO-SAVING...';
    }

    const totalTimeEst = updateCalculations();
    
    const metaData = {
        slug: metaSlug ? metaSlug.value : '',
        format: '',
        department: metaDepartment ? metaDepartment.value : '',
        reporter: metaReporter ? metaReporter.value : '',
        anchor: '',
        status: metaStatus ? metaStatus.value : 'DRAFT',
        estimated_time: totalTimeEst
    };

    const rowData = [];
    document.querySelectorAll('.script-row').forEach(row => {
        const cues = [];
        row.querySelectorAll('.cue-block').forEach(cb => {
            const type = cb.querySelector('.cue-type').value;
            const detail = cb.querySelector('.cue-detail').value;
            if (type || detail) {
                cues.push({ type: type, value: detail });
            }
        });

        const readVal = row.querySelector('.read-input').value;
        const wc = parseInt(row.querySelector('.word-count').innerText) || 0;
        const rt = parseInt(row.querySelector('.row-time').innerText) || 0;
        
        // Simulating the JSON structure
        rowData.push({
            type: "TEXT",
            leftColumn: {
                cues: cues
            },
            rightColumn: {
                text: readVal,
                wordCount: wc,
                readTimeSeconds: rt
            }
        });
    });

    const payload = {
        id: currentStoryId,
        metadata: metaData,
        content: rowData,
        csrf_token: window.currentUser ? window.currentUser.csrfToken : ''
    };

    try {
        const res = await fetch('api.php?action=save_story', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
        
        const result = await res.json();
        if (result.success) {
            currentStoryId = result.story_id;
            
            // Update URL if new story
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.get('id')) {
                window.history.pushState({}, '', `?id=${currentStoryId}`);
            }
            
            // Visual feedback
            btnSave.style.backgroundColor = '#4caf50';
            btnSave.innerText = 'SAVED ✓';
            setTimeout(() => {
                btnSave.style.backgroundColor = '';
                btnSave.innerText = 'SAVE STORY';
                btnSave.disabled = false;
            }, 2000);
            return true;
        } else {
            console.error('Save failed: ' + result.error);
            if (!isAutoSave) alert('Save failed: ' + result.error);
            btnSave.innerText = 'SAVE STORY';
            btnSave.disabled = false;
            return false;
        }
    } catch (e) {
        console.error(e);
        if (!isAutoSave) alert('Exception during save');
        btnSave.innerText = 'SAVE STORY';
        btnSave.disabled = false;
        return false;
    }
}

// Button Events
const newBtnEl = document.getElementById('btn-new') || document.getElementById('nav-new-story');
if (newBtnEl) {
    newBtnEl.addEventListener('click', (e) => {
        e.preventDefault();
        if (confirm('คุณต้องการเริ่มเขียนข่าวใหม่ใช่หรือไม่? (ข้อมูลที่ยังไม่ได้เซฟจะหายไป)')) {
            window.location.href = window.location.pathname;
        }
    });
}
btnSave.addEventListener('click', () => saveStory(false));
