import { Elements } from './config.js?v=3';
import { getMyStories, moveToBin } from './api.js?v=3';
import { escapeHTML } from './utils.js?v=3';
import { openPreviewModal } from './archive.js?v=3';

let currentTab = 0; // 0 for My Story, 1 for My Bin

export async function loadMyStoryResults() {
    if (!Elements.mystoryResults) return;
    Elements.mystoryResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">กำลังค้นหา...</div>';
    
    try {
        const result = await getMyStories(currentTab);
        if (result.success) {
            if (result.data.length === 0) {
                Elements.mystoryResults.innerHTML = '<div style="text-align:center; color:var(--text-secondary); margin-top:20px;">ไม่พบข่าว</div>';
                return;
            }
            Elements.mystoryResults.innerHTML = '';
            result.data.forEach(story => {
                const item = document.createElement('div');
                item.className = 'archive-item';
                let statusColor = '#333';
                if (story.status === 'READY') statusColor = '#2196F3';
                else if (story.status === 'REVIEW') statusColor = '#FF9800';
                else if (story.status === 'APPROVED') statusColor = '#4CAF50';
                
                let actionsHtml = `<div class="action-buttons">
                    <button class="btn-icon view-btn" title="View Story">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>`;
                
                // Only show Edit and Del if DRAFT and NOT in Bin
                if (story.status === 'DRAFT' && currentTab === 0) {
                    actionsHtml += `
                    <button class="btn-icon edit-btn" title="Edit Story">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button class="btn-icon del del-btn" title="Move to Bin">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                    </button>`;
                }
                actionsHtml += `</div>`;
                
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
                    ${actionsHtml}
                `;
                
                item.querySelector('.view-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    openPreviewModal(story.id, story.slug);
                });
                
                if (story.status === 'DRAFT' && currentTab === 0) {
                    item.querySelector('.edit-btn').addEventListener('click', (e) => {
                        e.stopPropagation();
                        window.location.href = '?id=' + story.id;
                    });
                    
                    item.querySelector('.del-btn').addEventListener('click', async (e) => {
                        e.stopPropagation();
                        const { value: isConfirmed } = await Swal.fire({
                            title: 'Confirm deletion',
                            text: 'คุณต้องการนำข่าวนี้ลงพื้นที่ Bin ใช่หรือไม่?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonColor: '#ff4757',
                            confirmButtonText: 'ใช่, ทิ้งข่าวเลย!'
                        });

                        if (isConfirmed) {
                            const res = await moveToBin(story.id);
                            if (res.success) {
                                loadMyStoryResults(); 
                            } else {
                                Swal.fire('Error', res.error || 'Failed to move to bin.', 'error');
                            }
                        }
                    });
                }

                Elements.mystoryResults.appendChild(item);
            });
        }
    } catch(e) {
        Elements.mystoryResults.innerHTML = `<div style="color:var(--danger); text-align:center;">เกิดข้อผิดพลาดในการโหลดข่าว</div>`;
    }
}

export function initMyStoryUI() {
    if (Elements.navMyStory) {
        Elements.navMyStory.addEventListener('click', (e) => {
            e.preventDefault();
            if (Elements.sidebar) Elements.sidebar.classList.remove('open');
            Elements.mystorySidebar.classList.add('open');
            loadMyStoryResults();
        });
    }

    if (Elements.btnCloseMystory) {
        Elements.btnCloseMystory.addEventListener('click', () => Elements.mystorySidebar.classList.remove('open'));
    }

    if (Elements.tabMyStory && Elements.tabMyBin) {
        Elements.tabMyStory.addEventListener('click', () => {
            currentTab = 0;
            Elements.tabMyStory.classList.add('active');
            Elements.tabMyBin.classList.remove('active');
            loadMyStoryResults();
        });
        Elements.tabMyBin.addEventListener('click', () => {
            currentTab = 1;
            Elements.tabMyBin.classList.add('active');
            Elements.tabMyStory.classList.remove('active');
            loadMyStoryResults();
        });
    }
}
