import { Elements, State } from './config.js?v=3';
import { getDepartments, getUsers, getStory, saveStoryData, lockStory, unlockStory } from './api.js?v=3';
import { updateCalculations, addNewRow } from './editor.js?v=3';
import { initAutocomplete } from './autocomplete.js?v=3';
import { initArchiveUI } from './archive.js?v=3';
import { setupPrint } from './print.js?v=3';
import { initMyStoryUI } from './mystory.js?v=3';
import { escapeHTML } from './utils.js?v=3';

const STOP_WORDS = new Set([
    'การ', 'ความ', 'ไป', 'มา', 'ที่', 'ซึ่ง', 'อัน', 'และ', 'หรือ', 'ของ', 'เป็น', 'ว่า', 'จะ', 'ให้', 'ได้', 'ก็', 'ใน', 'ด้วย', 'ผู้', 'มี', 'ไม่', 'จาก', 'แล้ว', 'กับ', 'นี้', 'นั้น', 'ทำ', 'วัน', 'รับ', 'ถึง', 'เพื่อ', 'โดย', 'ตาม'
]);

function extractKeywords(text) {
    if (!window.Intl || !Intl.Segmenter) return [];
    const segmenter = new Intl.Segmenter('th', { granularity: 'word' });
    const segments = segmenter.segment(text);
    const freqs = {};
    for (const segment of segments) {
        if (!segment.isWordLike) continue;
        const word = segment.segment.trim();
        if (word.length <= 2) continue;
        if (STOP_WORDS.has(word)) continue;
        freqs[word] = (freqs[word] || 0) + 1;
    }
    const sorted = Object.keys(freqs).map(w => ({ word: w, count: freqs[w] })).sort((a,b) => b.count - a.count);
    return sorted.slice(0, 5).map(item => item.word);
}

function applyUIPermissions() {
    if (!window.currentUser) return;
    const rId = window.currentUser.roleId;

    if (rId == 1 || rId == 4) {
        if (Elements.metaStatus) {
            const approveOpt = Elements.metaStatus.querySelector('option[value="APPROVED"]');
            if (approveOpt) approveOpt.disabled = true;
        }
    }
    if (rId == 1 || rId == 2) {
        if (Elements.metaDepartment) {
            Elements.metaDepartment.disabled = true;
        }
    }
}

window.loadAssignmentsList = async (deptId) => {
    if (!Elements.metaAssignment) return;
    try {
        const res = await fetch(`api.php?action=get_assignments&department_id=${deptId}&status=APPROVED`);
        const json = await res.json();
        const curVal = Elements.metaAssignment.value;
        Elements.metaAssignment.innerHTML = '<option value="">-- ไม่ผูกกับหมายข่าว --</option>';
        if (json.success && json.data) {
            json.data.forEach(a => {
                const title = `[${a.status}] ${a.title} (${a.reporter_name})`;
                Elements.metaAssignment.insertAdjacentHTML('beforeend', `<option value="${escapeHTML(a.id)}">${escapeHTML(title)}</option>`);
            });
        }
        if (curVal) Elements.metaAssignment.value = curVal;
    } catch (e) {}
};

window.loadComments = async () => {
    if (!State.currentStoryId) return;
    try {
        const res = await fetch(`api.php?action=get_story_comments&id=${State.currentStoryId}`);
        const json = await res.json();
        if (Elements.commentBadge) {
            Elements.commentBadge.innerText = json.data.length;
            if(json.data.length > 0) Elements.commentBadge.style.display = 'block'; else Elements.commentBadge.style.display = 'none';
        }
        
        let html = '';
        json.data.forEach(c => {
            html += `<div style="background:#2a2a2a; padding:10px; border-radius:8px; font-size:13px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:5px; color:#4caf50;">
                    <b>${escapeHTML(c.author_name || c.user_id)} <span style="font-size:11px; color:#888;">(${escapeHTML(c.role_name || '')})</span></b>
                    <small style="color:#aaa;">${escapeHTML(c.created_at)}</small>
                </div>
                <div>${escapeHTML(c.message)}</div>
            </div>`;
        });
        if (Elements.commentsList) Elements.commentsList.innerHTML = html || '<div style="color:#aaa; text-align:center;">No comments yet.</div>';
    } catch (e) {}
};

window.loadVersions = async () => {
    if (!State.currentStoryId) return;
    try {
        const res = await fetch(`api.php?action=get_story_versions&id=${State.currentStoryId}`);
        const json = await res.json();
        let html = '';
        json.data.forEach(v => {
            html += `<div style="background:#2a2a2a; padding:10px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                <div>
                    <b>Version ${v.version}</b><br>
                    <small style="color:#aaa;">${new Date(v.time * 1000).toLocaleString()}</small>
                </div>
                <button class="btn btn-outline" style="border:none; color:#4caf50;" onclick="window.restoreVersion(${v.version})">Restore</button>
            </div>`;
        });
        if (Elements.versionsList) Elements.versionsList.innerHTML = html || '<div style="color:#aaa; text-align:center;">No version history.</div>';
    } catch (e) {}
};

window.restoreVersion = async (version) => {
    if (!confirm(`Are you sure you want to restore Version ${version}? This will reload the editor.`)) return;
    try {
        await fetch('api.php?action=restore_story_version', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ csrf_token: window.currentUser.csrfToken, id: State.currentStoryId, version: version })
        });
        window.location.reload();
    } catch (e) {}
};

async function loadStory(id) {
    try {
        const result = await getStory(id);
        if (result.success) {
            State.currentStoryId = result.data.id;
            const meta = result.data.metadata;

            if (result.data.is_locked) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กำลังถูกแก้ไข!',
                    text: `ข่าวนี้กำลังถูกแก้ไขโดย: ${result.data.locked_by} คุณสามารถดูได้อย่างเดียว (Read Only)`,
                    confirmButtonText: 'รับทราบ'
                });
                if (Elements.btnSave) {
                    Elements.btnSave.innerText = `LOCKED BY ${result.data.locked_by}`;
                    Elements.btnSave.disabled = true;
                }
                if (Elements.btnAddRow) {
                    Elements.btnAddRow.style.display = 'none';
                }
                if (Elements.metaStatus) Elements.metaStatus.disabled = true;
                State.isReadOnly = true;
            } else {
                State.isReadOnly = false;
                const lockResult = await lockStory(id);
                if (lockResult.success) {
                    startLockHeartbeat(id);
                } else {
                    const errorMsg = lockResult.locked ? `ข่าวนี้ถูกล็อกกะทันหันโดย: ${lockResult.locked_by}` : (lockResult.error || 'สถานะบกพร่อง (Lock Error)');
                    Swal.fire({
                        icon: 'warning',
                        title: 'เกิดข้อผิดพลาด!',
                        text: errorMsg,
                        confirmButtonText: 'รับทราบ'
                    });
                    State.isReadOnly = true;
                    if (Elements.btnSave) {
                        Elements.btnSave.innerText = lockResult.locked ? `LOCKED BY ${lockResult.locked_by}` : `READ ONLY`;
                        Elements.btnSave.disabled = true;
                    }
                    if (Elements.btnAddRow) {
                        Elements.btnAddRow.style.display = 'none';
                    }
                    if (Elements.metaStatus) Elements.metaStatus.disabled = true;
                }
            }

            if (Elements.metaFormat) Elements.metaFormat.value = meta.format || '';
            if (Elements.metaSlug) Elements.metaSlug.value = meta.slug || '';
            if (Elements.metaDepartment) Elements.metaDepartment.value = meta.department || '';
            if (window.loadAssignmentsList && meta.department) await window.loadAssignmentsList(meta.department);
            if (Elements.metaAssignment) Elements.metaAssignment.value = meta.assignment || '';
            if (Elements.metaReporter) Elements.metaReporter.value = meta.reporter || '';
            if (Elements.metaStatus) Elements.metaStatus.value = meta.status || 'DRAFT';
            if (Elements.metaAnchor) Elements.metaAnchor.value = meta.anchor || '';
            State.storyKeywords = meta.keywords || '';

            const rId = window.currentUser ? window.currentUser.roleId : null;
            if (rId == 1 || rId == 2) {
                if (Elements.metaDepartment.value != window.currentUser.departmentId) {
                    if (Elements.btnSave) {
                        Elements.btnSave.innerText = 'READ ONLY';
                        Elements.btnSave.disabled = true;
                    }
                    if (Elements.btnAddRow) {
                        Elements.btnAddRow.style.display = 'none';
                    }
                    if (Elements.metaStatus) Elements.metaStatus.disabled = true;
                }
            }

            Elements.scriptBody.innerHTML = '';
            result.data.content.forEach(row => {
                const cues = row.leftColumn.cues || [];
                addNewRow(cues, row.rightColumn.text);
            });
            window.loadComments();
        } else {
            alert('Failed to load story: ' + result.error);
            addNewRow();
        }
    } catch (e) {
        console.error(e);
        alert('Error parsing story data');
    }
}

async function saveStory(isAutoSave = false) {
    if (State.isReadOnly || State.isSaving) return false;
    State.isSaving = true;

    try {
        const currentStatus = Elements.metaStatus ? Elements.metaStatus.value : 'DRAFT';

        const totalTimeEst = updateCalculations();
        let extractedAnchorText = "";
        const rowData = [];
        document.querySelectorAll('.script-row').forEach(row => {
            const cues = [];
            row.querySelectorAll('.cue-block').forEach(cb => {
                const type = cb.querySelector('.cue-type').value;
                const detail = cb.querySelector('.cue-detail').value;
                if (type || detail) cues.push({ type: type, value: detail });
            });

            const readVal = row.querySelector('.read-input').value;
            const wc = parseInt(row.querySelector('.word-count').innerText) || 0;
            const rt = parseInt(row.querySelector('.row-time').innerText) || 0;
            
            extractedAnchorText += readVal + " \n";

            rowData.push({
                type: "TEXT",
                leftColumn: { cues: cues },
                rightColumn: { text: readVal, wordCount: wc, readTimeSeconds: rt }
            });
        });

        // Intercept manual save if status changed to popup keyword validation
        if (!isAutoSave && State.initialStatus && currentStatus !== State.initialStatus) {
            const suggestedWords = extractKeywords(extractedAnchorText);
            const prefillKeywords = State.storyKeywords ? State.storyKeywords : suggestedWords.join(', ');
            
            const { value: keywords, isConfirmed } = await Swal.fire({
                title: "โปรดตรวจสอบ Keyword สำคัญ",
                html: `
                  <p style="font-size: 14px; color: #555; margin-bottom: 12px; font-family: 'Sarabun', sans-serif;">
                    คุณกำลังเปลี่ยนสถานะข่าวเป็น <b>${currentStatus}</b><br>
                    ระบบได้สกัดคำสำคัญออกมา ให้คุณสามารถตรวจสอบแก้ไขได้ก่อนส่งงาน
                  </p>
                  <input id="swal-input-keywords" class="swal2-input" value="${escapeHTML(prefillKeywords)}" style="font-family: 'Sarabun', sans-serif;">
                `,
                showCancelButton: true,
                confirmButtonText: 'Confirm & Save',
                cancelButtonText: 'Cancel',
                preConfirm: () => {
                    return document.getElementById("swal-input-keywords").value;
                }
            });
            
            if (!isConfirmed) return false;
            
            State.storyKeywords = keywords;
            State.initialStatus = currentStatus;
        }

        if (!isAutoSave) {
            Elements.btnSave.innerText = 'SAVING...';
            Elements.btnSave.disabled = true;
        } else {
            Elements.btnSave.innerText = 'AUTO-SAVING...';
        }

        const metaData = {
            slug: Elements.metaSlug ? Elements.metaSlug.value : '',
            format: Elements.metaFormat ? Elements.metaFormat.value : '',
            department: Elements.metaDepartment ? Elements.metaDepartment.value : '',
            assignment: Elements.metaAssignment ? Elements.metaAssignment.value : '',
            reporter: Elements.metaReporter ? Elements.metaReporter.value : '',
            anchor: extractedAnchorText.trim(),
            status: Elements.metaStatus ? Elements.metaStatus.value : 'DRAFT',
            estimated_time: totalTimeEst,
            keywords: State.storyKeywords || ''
        };

        const payload = {
            id: State.currentStoryId,
            metadata: metaData,
            content: rowData,
            is_autosave: isAutoSave,
            csrf_token: window.currentUser ? window.currentUser.csrfToken : ''
        };

        const result = await saveStoryData(payload);
        if (result.success) {
            State.currentStoryId = result.story_id;
            const urlParams = new URLSearchParams(window.location.search);
            if (!urlParams.get('id')) {
                window.history.pushState({}, '', `?id=${State.currentStoryId}`);
                startLockHeartbeat(State.currentStoryId);
            } else if (!State.lockInterval && !State.isReadOnly) {
                startLockHeartbeat(State.currentStoryId);
            }

            Elements.btnSave.style.backgroundColor = '#4caf50';
            Elements.btnSave.innerText = 'SAVED ✓';
            setTimeout(() => {
                Elements.btnSave.style.backgroundColor = '';
                Elements.btnSave.innerText = 'SAVE STORY';
                Elements.btnSave.disabled = false;
            }, 2000);
            return true;
        } else {
            console.error('Save failed: ' + result.error);
            if (!isAutoSave) {
                alert('Save failed: ' + result.error);
            }
            if (Elements.btnSave) {
                Elements.btnSave.innerText = 'SAVE STORY';
                Elements.btnSave.disabled = false;
            }
            return false;
        }
    } catch (e) {
        console.error(e);
        if (!isAutoSave) {
            alert('Exception during save');
        }
        if (Elements.btnSave) {
            Elements.btnSave.innerText = 'SAVE STORY';
            Elements.btnSave.disabled = false;
        }
        return false;
    } finally {
        State.isSaving = false;
    }
}

function startAutoSave() {
    if (State.autoSaveInterval) clearInterval(State.autoSaveInterval);
    State.autoSaveInterval = setInterval(async () => {
        if (State.isReadOnly) return;
        console.log("Auto-saving...");
        await saveStory(true);
    }, 5 * 60 * 1000);
}

function startLockHeartbeat(id) {
    if (State.lockInterval) clearInterval(State.lockInterval);
    State.lockInterval = setInterval(async () => {
        if (!State.isReadOnly) {
            await lockStory(id);
        }
    }, 2 * 60 * 1000);
}

document.addEventListener('DOMContentLoaded', async () => {
    initAutocomplete(updateCalculations);
    initArchiveUI();
    initMyStoryUI();
    setupPrint(saveStory);

    if (Elements.btnAddRow) Elements.btnAddRow.addEventListener('click', () => addNewRow());
    if (Elements.btnSave) Elements.btnSave.addEventListener('click', () => saveStory(false));
    if (Elements.newBtnEl) {
        Elements.newBtnEl.addEventListener('click', async (e) => {
            e.preventDefault();
            if (confirm('คุณต้องการเริ่มเขียนข่าวใหม่ใช่หรือไม่? (ข้อมูลที่ยังไม่ได้เซฟจะหายไป)')) {
                if (State.lockInterval) clearInterval(State.lockInterval);
                if (State.currentStoryId && !State.isReadOnly) {
                    await unlockStory(State.currentStoryId);
                }
                window.location.href = window.location.pathname;
            }
        });
    }

    try {
        const result = await getDepartments();
        if (result.success && Elements.metaDepartment) {
            Elements.metaDepartment.innerHTML = '';
            if (Elements.searchDept) Elements.searchDept.innerHTML = '<option value="">ทุกสังกัด (All)</option>';
            result.data.forEach(dept => {
                const opt = document.createElement('option');
                opt.value = dept.id;
                opt.textContent = dept.name;
                Elements.metaDepartment.appendChild(opt);

                if (Elements.searchDept) {
                    const sOpt = document.createElement('option');
                    sOpt.value = dept.id;
                    sOpt.textContent = dept.name;
                    Elements.searchDept.appendChild(sOpt);
                }
            });
        }
    } catch (e) { console.error("Could not load departments", e); }

    try {
        const resultUsers = await getUsers();
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
    } catch (e) { console.error("Could not load users", e); }

    applyUIPermissions();

    const urlParams = new URLSearchParams(window.location.search);
    const id = urlParams.get('id');
    const autoPrint = urlParams.get('print');

    if (id) {
        await loadStory(id);
        State.initialStatus = Elements.metaStatus ? Elements.metaStatus.value : 'DRAFT';
        // preload existing keywords via GET if available, handled in loadStory if added later
        
        if (autoPrint === '1') {
            setTimeout(() => {
                if (window.Elements && window.Elements.btnPrint) {
                    window.Elements.btnPrint.click();
                } else {
                    const pb = document.getElementById('btn-print');
                    if (pb) pb.click();
                }
            }, 700);
        }
    } else {
        if (window.currentUser) {
            if (Elements.metaDepartment) Elements.metaDepartment.value = window.currentUser.departmentId || '';
            if (Elements.metaReporter && !Elements.metaReporter.value) Elements.metaReporter.value = window.currentUser.fullName || '';
        }
        State.initialStatus = 'DRAFT';
        addNewRow();
        if (Elements.metaDepartment && Elements.metaDepartment.value) {
            window.loadAssignmentsList(Elements.metaDepartment.value);
        }
    }

    if (Elements.metaDepartment) {
        Elements.metaDepartment.addEventListener('change', (e) => {
            if (window.loadAssignmentsList) window.loadAssignmentsList(e.target.value);
        });
    }

    if (Elements.metaFormat) {
        Elements.metaFormat.addEventListener('change', (e) => {
            const format = e.target.value;
            const rows = document.querySelectorAll('.script-row');
            if (rows.length === 1 && rows[0].querySelector('.read-input').value.trim() === '') {
                Elements.scriptBody.innerHTML = '';
                if (format === 'PKG') {
                    addNewRow([{type: 'CG', value: 'ชื่อ-นามสกุล/นักข่าวภาคสนาม'}], '[Reporter Stand-up or Voice Over]');
                    addNewRow([{type: 'SOT', value: 'ชื่อ/ตำแหน่ง แหล่งข่าว'}], '[เสียงสัมภาษณ์]');
                } else if (format === 'LIVE') {
                    addNewRow([{type: 'LIVE', value: 'สดจาก...'}], '[รายงานสด]');
                } else if (format === 'INTERVIEW') {
                    addNewRow([{type: 'VO', value: ''}], '[บรรยายนำเข้าการสัมภาษณ์]');
                    addNewRow([{type: 'SOT', value: 'ชื่อ/ตำแหน่ง แหล่งข่าว'}], '[เสียงสัมภาษณ์]');
                } else {
                    addNewRow();
                }
            }
        });
    }

    if (Elements.btnComments) {
        Elements.btnComments.addEventListener('click', () => {
            Elements.commentsSidebar.classList.add('open');
            window.loadComments();
        });
        Elements.btnCloseComments.addEventListener('click', () => {
            Elements.commentsSidebar.classList.remove('open');
        });
        Elements.btnSendComment.addEventListener('click', async () => {
            const msg = Elements.commentInput.value.trim();
            if (!msg || !State.currentStoryId) return;
            try {
                await fetch('api.php?action=add_story_comment', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({ csrf_token: window.currentUser.csrfToken, id: State.currentStoryId, message: msg })
                });
                Elements.commentInput.value = '';
                window.loadComments();
            } catch (e) {}
        });
    }

    if (Elements.btnVersions) {
        Elements.btnVersions.addEventListener('click', () => {
            Elements.versionsSidebar.classList.add('open');
            window.loadVersions();
        });
        Elements.btnCloseVersions.addEventListener('click', () => {
            Elements.versionsSidebar.classList.remove('open');
        });
    }

    startAutoSave();

    window.addEventListener('beforeunload', () => {
        if (State.lockInterval) clearInterval(State.lockInterval);
        if (State.currentStoryId && !State.isReadOnly) {
            unlockStory(State.currentStoryId);
        }
    });
});
