import { Elements, State } from './config.js?v=3';
import { getStory, searchStories } from './api.js?v=3';
import { escapeHTML } from './utils.js?v=3';

export const closeModal = () => { if (Elements.modal) Elements.modal.classList.remove('open'); };

export async function openPreviewModal(id, title) {
    if (!Elements.modal) return;
    
    // Reset window position and state
    Elements.modal.classList.remove('minimized', 'maximized');
    Elements.modal.style.top = '100px';
    Elements.modal.style.left = '100px';
    Elements.modal.style.width = '600px';
    Elements.modal.style.height = '70vh';

    Elements.previewTitle.innerText = title || 'Preview';
    Elements.previewBody.innerHTML = '<div style="text-align:center;">Loading...</div>';
    Elements.modal.classList.add('open');
    State.previewingStoryId = id;
    
    try {
        const result = await getStory(id);
        if (result.success) {
            Elements.previewBody.innerHTML = '';
            if (result.data.content && result.data.content.length > 0) {
                result.data.content.forEach((row, index) => {
                    const b = document.createElement('div');
                    b.className = 'preview-block';
                    let cueHtml = '';
                    if (row.leftColumn && row.leftColumn.cues) {
                        row.leftColumn.cues.forEach(c => {
                            const cueText = c.value || c.detail || '';
                            cueHtml += `<div class="preview-cue">[${escapeHTML(c.type)}] ${escapeHTML(cueText)}</div>`;
                        });
                    }
                    b.innerHTML = `
                        ${cueHtml}
                        <div style="font-family: var(--font-thai);">${escapeHTML(row.rightColumn.text)}</div>
                    `;
                    Elements.previewBody.appendChild(b);
                });
            } else {
                Elements.previewBody.innerHTML = '<div style="text-align:center; color:var(--text-secondary);">เนื้อหาว่างเปล่า</div>';
            }
        }
    } catch(e) {
        Elements.previewBody.innerHTML = `<div style="color:var(--danger); text-align:center;">เกิดข้อผิดพลาดในการโหลดเนื้อหา</div>`;
    }
}

export function initArchiveUI() {
    if (Elements.btnArchive) Elements.btnArchive.addEventListener('click', (e) => {
        e.preventDefault();
        if (Elements.mystorySidebar) Elements.mystorySidebar.classList.remove('open');
        Elements.sidebar.classList.add('open');
        if (window.currentUser && Elements.searchDept) {
            Elements.searchDept.value = window.currentUser.departmentId;
        }
    });

    if (Elements.btnCloseArchive) Elements.btnCloseArchive.addEventListener('click', () => Elements.sidebar.classList.remove('open'));

    if (Elements.btnDoSearch) {
        Elements.btnDoSearch.addEventListener('click', async () => {
            const dId = Elements.searchDept.value;
            const kw = Elements.searchKw.value.trim();
            Elements.archiveResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">กำลังค้นหา...</div>';
            try {
                const result = await searchStories(dId, kw);
                if (result.success) {
                    if (result.data.length === 0) {
                        Elements.archiveResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">ไม่พบข่าวที่ค้นหา</div>';
                        return;
                    }
                    Elements.archiveResults.innerHTML = '';
                    result.data.forEach(story => {
                        const item = document.createElement('div');
                        item.className = 'archive-item';
                        let statusColor = '#333';
                        if (story.status === 'READY') statusColor = '#2196F3';
                        else if (story.status === 'REVIEW') statusColor = '#FF9800';
                        else if (story.status === 'APPROVED') statusColor = '#4CAF50';
                        
                        item.innerHTML = `
                            <div class="archive-item-header">
                                <div class="archive-item-title">${escapeHTML(story.slug || 'Untitled Story')}</div>
                                <span class="status-badge" style="background-color: ${escapeHTML(statusColor)};">${escapeHTML(story.status)}</span>
                            </div>
                            <div class="archive-item-meta">
                                <div class="meta-row-info">
                                    <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg> ${escapeHTML(story.department_name || '-')}</span>
                                    <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> ${escapeHTML(story.updated_at)}</span>
                                </div>
                            </div>
                        `;
                        item.addEventListener('click', () => openPreviewModal(story.id, story.slug));
                        Elements.archiveResults.appendChild(item);
                    });
                } else {
                    Elements.archiveResults.innerHTML = `<div style="color:var(--danger); text-align:center;">${result.error}</div>`;
                }
            } catch(e) {
                Elements.archiveResults.innerHTML = `<div style="color:var(--danger); text-align:center;">เกิดข้อผิดพลาดในการเชื่อมต่อ</div>`;
            }
        });
    }

    if (Elements.btnClosePreview) Elements.btnClosePreview.addEventListener('click', closeModal);
    if (Elements.btnCancelPreview) Elements.btnCancelPreview.addEventListener('click', closeModal);
    if (Elements.btnLoadPreview) Elements.btnLoadPreview.addEventListener('click', () => {
        if (State.previewingStoryId) {
            window.location.href = `index.php?id=${State.previewingStoryId}`;
        }
    });

    // Floating Window Minimize Logic
    const btnMinPreview = document.getElementById('btn-min-preview');
    if (btnMinPreview && Elements.modal) {
        btnMinPreview.addEventListener('click', () => {
            Elements.modal.classList.remove('maximized');
            Elements.modal.classList.toggle('minimized');
        });
    }

    // Floating Window Maximize Logic
    const btnMaxPreview = document.getElementById('btn-max-preview');
    if (btnMaxPreview && Elements.modal) {
        btnMaxPreview.addEventListener('click', () => {
            Elements.modal.classList.remove('minimized');
            Elements.modal.classList.toggle('maximized');
        });
    }

    // Floating Window Draggable Logic
    const previewHeader = document.getElementById('preview-header');
    if (previewHeader && Elements.modal) {
        let isDragging = false;
        let startX, startY, initialLeft, initialTop;

        previewHeader.addEventListener('mousedown', (e) => {
            if (e.target.closest('button')) return; // Prevent drag when clicking buttons
            if (Elements.modal.classList.contains('minimized') || Elements.modal.classList.contains('maximized')) return; // Disable drag if minimized or maximized
            
            isDragging = true;
            startX = e.clientX;
            startY = e.clientY;
            
            const rect = Elements.modal.getBoundingClientRect();
            initialLeft = rect.left;
            initialTop = rect.top;

            // Clear right/bottom constraints to preserve correct width/height resizing
            Elements.modal.style.right = 'auto';
            Elements.modal.style.bottom = 'auto';
            
            previewHeader.style.cursor = 'grabbing';
            e.preventDefault(); // Prevent text selection
        });

        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            Elements.modal.style.left = `${initialLeft + dx}px`;
            Elements.modal.style.top = `${initialTop + dy}px`;
        });

        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                previewHeader.style.cursor = 'grab';
            }
        });
        
        previewHeader.style.cursor = 'grab';
        previewHeader.style.userSelect = 'none';
    }
}
