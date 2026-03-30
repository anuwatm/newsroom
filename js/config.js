export const WPM_RATE = 3;

export const Elements = {
    scriptBody: document.getElementById('script-body'),
    btnAddRow: document.getElementById('btn-add-row'),
    btnSave: document.getElementById('btn-save'),
    btnPrint: document.getElementById('btn-print'),
    template: document.getElementById('row-template'),
    metaSlug: document.getElementById('meta-slug'),
    metaDepartment: document.getElementById('meta-department'),
    metaReporter: document.getElementById('meta-reporter'),
    metaStatus: document.getElementById('meta-status'),
    totalTimeDisplay: document.getElementById('total-time'),
    sidebar: document.getElementById('archive-sidebar'),
    btnArchive: document.getElementById('btn-archive') || document.getElementById('nav-find-story'),
    btnCloseArchive: document.getElementById('btn-close-archive'),
    searchDept: document.getElementById('search-department'),
    searchKw: document.getElementById('search-keyword'),
    btnDoSearch: document.getElementById('btn-do-search'),
    archiveResults: document.getElementById('archive-results'),
    modal: document.getElementById('preview-modal'),
    btnClosePreview: document.getElementById('btn-close-preview'),
    btnLoadPreview: document.getElementById('btn-load-preview'),
    btnCancelPreview: document.getElementById('btn-cancel-preview'),
    previewTitle: document.getElementById('preview-title'),
    previewBody: document.getElementById('preview-body'),
    cmdMenu: document.getElementById('cmd-autocomplete'),
    newBtnEl: document.getElementById('btn-new') || document.getElementById('nav-new-story'),
};

export const State = {
    currentStoryId: null,
    previewingStoryId: null,
    isAutoSaving: false,
    autoSaveInterval: null,
    acTarget: null,
    acCursorPos: -1,
    acSelectedIndex: 0
};
