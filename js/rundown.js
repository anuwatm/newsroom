/**
 * rundown.js
 * Manages rundown UI, polling mechanisms, drag and drop logic.
 */

let currentRundownId = null;
let currentTargetTrt = 0;
let pollInterval = null;
let isDragging = false;
let draggedRowId = null;
let latestSnapshot = "";
let countdownInterval = null;

// Format seconds to MM:SS
function formatTime(secs) {
    if (isNaN(secs) || secs < 0) return "00:00";
    const m = Math.floor(secs / 60);
    const s = secs % 60;
    return `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
}

function escapeHTML(str) {
    if (!str) return '';
    return String(str).replace(/[&<>'"]/g, tag => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
    }[tag] || tag));
}

function formatThaiDate(dateStr) {
    if (!dateStr) return '';
    const dTime = new Date(dateStr.replace(/-/g, "/"));
    const months = ["ม.ค.", "ก.พ.", "มี.ค.", "เม.ย.", "พ.ค.", "มิ.ย.", "ก.ค.", "ส.ค.", "ก.ย.", "ต.ค.", "พ.ย.", "ธ.ค."];
    return `${dTime.getHours().toString().padStart(2, '0')}:${dTime.getMinutes().toString().padStart(2, '0')} น. — ${dTime.getDate()} ${months[dTime.getMonth()]} ${dTime.getFullYear() + 543}`;
}

document.addEventListener('DOMContentLoaded', () => {
    initView();

    const btnCreate = document.getElementById('btn-create-new');
    if (btnCreate) {
        btnCreate.addEventListener('click', createNewRundown);
    }
    
    const btnLock = document.getElementById('btn-lock-board');
    if (btnLock) {
        btnLock.addEventListener('click', toggleLock);
    }

    const btnAddStore = document.getElementById('btn-add-story');
    if (btnAddStore) {
        btnAddStore.addEventListener('click', openAddStoryPrompt);
    }

    const btnAddBreak = document.getElementById('btn-add-break');
    if (btnAddBreak) {
        btnAddBreak.addEventListener('click', openAddBreakPrompt);
    }
    
    const btnPrintRundown = document.getElementById('btn-print-rundown');
    if (btnPrintRundown) {
        btnPrintRundown.addEventListener('click', () => {
            if (currentRundownId) {
                window.open('print_rundown.php?id=' + currentRundownId, '_blank');
            }
        });
    }

    const sInput = document.getElementById('story-search-input');
    if (sInput) {
        let storySearchTimeout = null;
        sInput.addEventListener('keyup', (e) => {
            clearTimeout(storySearchTimeout);
            storySearchTimeout = setTimeout(() => doStorySearch(e.target.value), 400);
        });
    }
    
    window.addEventListener('pagehide', () => {
        if (pollInterval) clearInterval(pollInterval);
        if (countdownInterval) clearInterval(countdownInterval);
    });
});

async function initView() {
    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id');
    
    if (id) {
        currentRundownId = id;
        document.getElementById('selection-screen').style.display = 'none';
        document.getElementById('board-screen').style.display = 'block';
        fetchRundownData();
        // Start polling
        pollInterval = setInterval(fetchRundownData, 3000);
    } else {
        document.getElementById('selection-screen').style.display = 'block';
        document.getElementById('board-screen').style.display = 'none';
        loadRundownList();
    }
}

async function loadRundownList() {
    try {
        const res = await fetch('api.php?action=get_rundowns');
        const json = await res.json();
        if (json.success) {
            const tbody = document.getElementById('rundown-list-body');
            tbody.innerHTML = '';
            json.data.forEach(rd => {
                const tr = document.createElement('tr');
                tr.style.borderBottom = '1px solid #333';
                tr.innerHTML = `
                    <td style="padding: 12px; font-weight:bold; color:#fff;">${escapeHTML(rd.name)}</td>
                    <td style="padding: 12px;">${formatThaiDate(rd.broadcast_time)}</td>
                    <td style="padding: 12px;">${formatTime(rd.target_trt)}</td>
                    <td style="padding: 12px;">
                        <a href="?id=${escapeHTML(rd.id)}" class="btn-outline" style="text-decoration:none;">Open</a>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        }
    } catch (e) {
        console.error(e);
    }
}

async function createNewRundown() {
    try {
        const pRes = await fetch('api.php?action=get_programs');
        const pJson = await pRes.json();
        let optionsHtml = '';
        if (pJson.success && pJson.data.length > 0) {
            pJson.data.forEach(p => {
                optionsHtml += `<option value="${p.id}" data-dur="${p.duration}" data-break="${p.break_count}">${escapeHTML(p.name)} (เป้า: ${Math.floor(p.duration/60)}m, Break: ${p.break_count})</option>`;
            });
        }
        
        const { value: formValues } = await Swal.fire({
            title: 'Create Rundown',
            html:
                (optionsHtml ? `<select id="swal-rd-prog" class="swal2-input"><option value="" disabled selected>Select Master Program</option>${optionsHtml}</select>` : '<p style="color:#aaa;">No master programs exist. Use Admin to create one.</p>') +
                '<input id="swal-rd-name" class="swal2-input" placeholder="Custom Program Name Override (Optional)">' +
                '<input id="swal-rd-date" type="datetime-local" class="swal2-input">',
            focusConfirm: false,
            preConfirm: () => {
                const progSel = document.getElementById('swal-rd-prog');
                if (!progSel) return false;
                
                const selOpt = progSel.options[progSel.selectedIndex];
                const custName = document.getElementById('swal-rd-name').value;
                const dTime = document.getElementById('swal-rd-date').value;
                
                if (!progSel.value || !dTime) {
                    Swal.showValidationMessage('Please select a program and set a broadcast time.');
                    return false;
                }
                
                return {
                    program_id: progSel.value,
                    name: custName || progSel.options[progSel.selectedIndex].text.split(' (')[0],
                    target_trt: selOpt.getAttribute('data-dur'),
                    break_count: selOpt.getAttribute('data-break'),
                    time: dTime
                };
            }
        });

        if (formValues && formValues.program_id) {
            const res = await fetch('api.php?action=create_rundown', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    csrf_token: window.RUNDOWN_ENV.csrfToken,
                    program_id: formValues.program_id,
                    name: formValues.name,
                    broadcast_time: formValues.time.replace('T', ' ') + ':00',
                    target_trt: formValues.target_trt,
                    break_count: formValues.break_count
                })
            });
            const json = await res.json();
            if (json.success) {
                window.location.href = `?id=${json.id}`;
            } else {
                Swal.fire('Error', json.error, 'error');
            }
        }
    } catch(e) {
        console.error(e);
    }
}

async function fetchRundownData() {
    if (isDragging) return; // Prevent overwriting DOM during active drag interaction
    
    try {
        const res = await fetch(`api.php?action=get_rundown_data&id=${currentRundownId}`);
        const json = await res.json();
        
        if (json.success) {
            const hash = JSON.stringify(json.data);
            if (hash !== latestSnapshot) {
                latestSnapshot = hash;
                renderBoard(json.data);
            }
        }
    } catch (e) {
        console.error(e);
    }
}

function renderBoard(data) {
    // 1. Header
    currentTargetTrt = parseInt(data.target_trt);
    let locked = parseInt(data.is_locked) === 1;
    
    const dStr = formatThaiDate(data.broadcast_time);
    
    document.getElementById('rd-title').innerText = `${data.name} ${dStr}`;
    
    updateCountdownManager(data.broadcast_time);
    
    // 2. Stories
    const container = document.getElementById('rundown-rows');
    container.innerHTML = '';
    
    let activeTotalTrt = 0;
    let indexCount = 1;
    
    const btnLock = document.getElementById('btn-lock-board');
    if (btnLock) {
        btnLock.innerText = locked ? "Unlock Rundown" : "Lock Rundown";
    }

    const disableMods = locked || window.RUNDOWN_ENV.roleId != 3;

    data.stories.forEach(story => {
        const row = document.createElement('div');
        row.className = `table-row ${story.is_dropped == 1 ? 'dropped' : ''}`;
        row.dataset.id = story.rundown_story_id;
        
        if (!disableMods) {
            row.draggable = true;
            row.addEventListener('dragstart', handleDragStart);
            row.addEventListener('dragover', handleDragOver);
            row.addEventListener('drop', handleDrop);
            row.addEventListener('dragend', handleDragEnd);
        }

        const est = parseInt(story.estimated_time) || 0;
        let rowNumDisplay = '-';
        if (story.is_dropped == 0) {
            activeTotalTrt += est;
            rowNumDisplay = indexCount++;
        }

        const upDate = new Date(story.updated_at);
        const upStr = `${upDate.getHours().toString().padStart(2, '0')}:${upDate.getMinutes().toString().padStart(2, '0')}`;
        
        const safeFormat = escapeHTML(story.format || 'OC');
        const safeFormatClass = (story.format || 'OC').replace(/[^a-zA-Z0-9_-]/g, "");
        const fBadge = `<span class="format-badge format-${safeFormatClass}">${safeFormat}</span>`;
        const actionBtn = story.is_dropped == 1 ? 
            `<button class="btn-outline toggle-drop" data-id="${story.rundown_story_id}" data-val="0" title="Restore" style="border:none;"><i class="fa-solid fa-rotate-left"></i></button>` :
            `<button class="btn-outline toggle-drop" data-id="${story.rundown_story_id}" data-val="1" title="Drop Story" style="border:none; color:#f44336;"><i class="fa-solid fa-ban"></i></button>`;

        const editBtn = story.format === 'BREAK' ? '' : `<button class="btn-outline" onclick="window.open('index.php?id=${story.id}', '_blank')" title="Edit Story" style="border:none;"><i class="fa-solid fa-pen"></i></button>`;
        const printBtn = story.format === 'BREAK' ? '' : `<button class="btn-outline" onclick="window.open('print_story.php?id=${story.id}', '_blank')" title="Print Story" style="border:none; color:#ddd;"><i class="fa-solid fa-print"></i></button>`;

        let pColor = '#4caf50';
        if (story.is_dropped == 1) pColor = '#555';
        else if (story.status !== 'APPROVED') pColor = '#ff9800';

        if (story.format === 'BREAK') {
            row.innerHTML = `
                <div class="drag-handle">${disableMods ? '' : '::'}</div>
                <div class="row-num">${rowNumDisplay}</div>
                <div style="grid-column: span 5; text-align: center; color: #aaa; background: #2a2a2a; border-radius: 8px; padding: 6px; font-weight: bold; letter-spacing: 2px;">
                    ${escapeHTML(story.slug)}
                </div>
                <!-- <div></div><div></div><div></div><div></div><div></div> skipped for grid span -->
                <div class="trt-bar-container">
                    <div class="trt-time">${formatTime(est)}</div>
                    <div class="trt-bar-bg" style="width: 100px;">
                        <div class="trt-bar-fill" style="background:#555; width: ${Math.min(100, (est/currentTargetTrt)*300)}%"></div>
                    </div>
                </div>
                <div class="row-actions">
                    ${disableMods ? '' : actionBtn}
                </div>
            `;
        } else {
            row.innerHTML = `
                <div class="drag-handle">${disableMods ? '' : '::'}</div>
                <div class="row-num">${rowNumDisplay}</div>
                <div>
                    <div class="headline-text">${escapeHTML(story.slug) || 'Untitled'}</div>
                    <div class="headline-sub">${story.is_dropped == 1 ? 'DROPPED — ยกเลิกแล้ว' : 'อัปเดต ' + escapeHTML(upStr)}</div>
                </div>
                <div>${fBadge}</div>
                <div style="font-size: 14px;">${escapeHTML(story.reporter) || '-'}</div>
                <div style="color: #bbb;">${escapeHTML(story.department_name) || '-'}</div>
                <div style="text-align: center;"><div class="status-dot status-${escapeHTML(story.status)}"></div></div>
                <div class="trt-bar-container">
                    <div class="trt-time">${formatTime(est)}</div>
                    <div class="trt-bar-bg" style="width: 100px;">
                        <div class="trt-bar-fill" style="background:${pColor}; width: ${Math.min(100, (est/currentTargetTrt)*300)}%"></div>
                    </div>
                </div>
                <div class="row-actions">
                    ${disableMods ? `<button class="btn-outline" onclick="window.open('index.php?id=${story.id}', '_blank')" title="View"><i class="fa-solid fa-eye"></i></button> ${printBtn}` : editBtn + ' ' + printBtn + ' ' + actionBtn}
                </div>
            `;
        }
        
        container.appendChild(row);
    });
    
    document.getElementById('rd-story-count').innerText = data.stories.length;
    
    // Updates TRT Board
    const trtValElem = document.getElementById('rd-trt-value');
    const trtSubElem = document.getElementById('rd-trt-sub');
    trtValElem.innerText = formatTime(activeTotalTrt);
    
    if (activeTotalTrt > currentTargetTrt) {
        trtValElem.className = 'stat-value val-red';
        trtSubElem.className = 'stat-sub val-red';
        trtSubElem.innerText = `เกิน ${formatTime(activeTotalTrt - currentTargetTrt)} (เป้า ${formatTime(currentTargetTrt)})`;
    } else {
        trtValElem.className = 'stat-value val-green';
        trtSubElem.className = 'stat-sub val-white';
        trtSubElem.innerText = `ขาดอีก ${formatTime(currentTargetTrt - activeTotalTrt)} (เป้า ${formatTime(currentTargetTrt)})`;
    }
    
    // Bind toggle buttons
    document.querySelectorAll('.toggle-drop').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            const rsId = e.currentTarget.getAttribute('data-id');
            const targetVal = e.currentTarget.getAttribute('data-val');
            await fetch('api.php?action=toggle_rundown_story_drop', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({
                    csrf_token: window.RUNDOWN_ENV.csrfToken,
                    id: rsId,
                    is_dropped: targetVal
                })
            });
            fetchRundownData();
        });
    });
}

function handleDragStart(e) {
    isDragging = true;
    draggedRowId = this;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/html', this.innerHTML);
    setTimeout(() => this.classList.add('dragging'), 0);
}

function handleDragOver(e) {
    if (e.preventDefault) e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    const b = e.target.closest('.table-row');
    if (b && b !== draggedRowId) b.classList.add('over');
    return false;
}

function handleDrop(e) {
    if (e.stopPropagation) e.stopPropagation();
    const dropTarget = e.target.closest('.table-row');
    if (!dropTarget) return false;
    if (draggedRowId !== dropTarget) {
        // Swap or Insert
        const container = document.getElementById('rundown-rows');
        const rows = Array.from(container.children);
        const dragIndex = rows.indexOf(draggedRowId);
        const dropIndex = rows.indexOf(dropTarget);
        
        if (dragIndex < dropIndex) {
            container.insertBefore(draggedRowId, dropTarget.nextSibling);
        } else {
            container.insertBefore(draggedRowId, dropTarget);
        }
        
        saveNewOrder();
    }
    return false;
}

function handleDragEnd(e) {
    isDragging = false;
    this.classList.remove('dragging');
    document.querySelectorAll('.table-row').forEach(row => row.classList.remove('over'));
}

async function saveNewOrder() {
    const rows = document.querySelectorAll('#rundown-rows .table-row');
    const ids = Array.from(rows).map(row => row.dataset.id);
    
    try {
        await fetch('api.php?action=update_rundown_order', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: window.RUNDOWN_ENV.csrfToken,
                rundown_id: currentRundownId,
                ids: ids
            })
        });
        fetchRundownData();
    } catch (e) {
        console.error(e);
    }
}

async function toggleLock() {
    const isLocked = document.getElementById('btn-lock-board').innerText.includes("Unlock") ? 0 : 1;
    await fetch('api.php?action=toggle_lock_rundown', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            csrf_token: window.RUNDOWN_ENV.csrfToken,
            id: currentRundownId,
            is_locked: isLocked
        })
    });
    fetchRundownData();
}

function closeStorySearchModal() {
    document.getElementById('story-search-modal').style.display = 'none';
}

async function openAddStoryPrompt() {
    document.getElementById('story-search-modal').style.display = 'block';
    document.getElementById('story-search-input').value = '';
    document.getElementById('story-search-results').innerHTML = '<p style="color:#888; padding: 12px;">Start typing above to search across stories...</p>';
    document.getElementById('story-search-input').focus();
}

async function doStorySearch(keyword) {
    if (!keyword) return;
    const resDiv = document.getElementById('story-search-results');
    resDiv.innerHTML = '<p style="padding:12px; color:#888;">Searching...</p>';
    
    try {
        const res = await fetch(`api.php?action=search_stories&exclude_draft=1&keyword=${encodeURIComponent(keyword)}`);
        const json = await res.json();
        resDiv.innerHTML = '';
        
        if (json.success && json.data.length > 0) {
            json.data.forEach(s => {
                const rd = document.createElement('div');
                rd.style.padding = '12px'; 
                rd.style.borderBottom = '1px solid #333'; 
                rd.style.cursor = 'pointer';
                const statusEsc = escapeHTML(s.status);
                const slugEsc = escapeHTML(s.slug);
                const deptEsc = escapeHTML(s.department_name);
                const upEsc = escapeHTML(s.updated_at);
                rd.innerHTML = `<span style="font-weight:bold; color:#4caf50;">[${statusEsc}]</span> ${slugEsc} <div style="font-size:12px; color:#888; margin-top:4px;">${deptEsc} - ${upEsc}</div>`;
                
                rd.addEventListener('click', async () => {
                    rd.style.pointerEvents = 'none'; // Bug 6: prevent double clicking
                    closeStorySearchModal();
                    const addRes = await fetch('api.php?action=add_rundown_story', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/json'},
                        body: JSON.stringify({
                            csrf_token: window.RUNDOWN_ENV.csrfToken,
                            rundown_id: currentRundownId,
                            story_id: s.id
                        })
                    });
                    const addJson = await addRes.json();
                    if (addJson.success) {
                        fetchRundownData();
                    } else {
                        Swal.fire('Error', addJson.error, 'error');
                    }
                });
                
                rd.addEventListener('mouseover', () => rd.style.background = '#2a2a2a');
                rd.addEventListener('mouseout', () => rd.style.background = 'transparent');
                resDiv.appendChild(rd);
            });
        } else {
            resDiv.innerHTML = '<p style="padding:12px;">No matching stories found.</p>';
        }
    } catch(e) {
        resDiv.innerHTML = '<p style="padding:12px; color:red;">Failed to retrieve stories.</p>';
    }
}

async function openAddBreakPrompt() {
    const { value: durationForm } = await Swal.fire({
        title: 'Add Commercial Break',
        input: 'number',
        inputLabel: 'Break Duration (seconds)',
        inputValue: 180,
        showCancelButton: true
    });
    
    if (durationForm) {
        const res = await fetch('api.php?action=add_rundown_break', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                csrf_token: window.RUNDOWN_ENV.csrfToken,
                rundown_id: currentRundownId,
                duration: durationForm
            })
        });
        const json = await res.json();
        if (json.success) {
            fetchRundownData();
        } else {
            Swal.fire('Error', json.error, 'error');
        }
    }
}

function updateCountdownManager(targetTimeStr) {
    if (countdownInterval) clearInterval(countdownInterval);
    if (!targetTimeStr) return;
    const targetMs = new Date(targetTimeStr.replace(/-/g, "/")).getTime(); 
    
    countdownInterval = setInterval(() => {
        const el = document.getElementById('rd-countdown');
        if (!el) return;
        const now = new Date().getTime();
        const diff = Math.floor((targetMs - now) / 1000);
        
        if (diff <= 0) {
            el.innerText = "00:00";
            el.classList.add('val-red');
            el.classList.remove('val-white');
            el.nextElementSibling.innerText = "ON AIR";
        } else {
            const h = Math.floor(diff / 3600);
            const m = Math.floor((diff % 3600) / 60);
            const s = diff % 60;
            if (h > 0) {
                el.innerText = `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            } else {
                el.innerText = `${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`;
            }
            el.classList.remove('val-red');
            el.classList.add('val-white');
            el.nextElementSibling.innerText = " countdown";
        }
    }, 1000);
}

