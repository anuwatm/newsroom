export async function getDepartments() {
    const res = await fetch('api.php?action=get_departments');
    return res.json();
}

export async function getUsers() {
    const res = await fetch('api.php?action=get_users');
    return res.json();
}

export async function getStory(id) {
    const res = await fetch(`api.php?action=get_story&id=${id}`);
    return res.json();
}

export async function searchStories(dId, kw) {
    const res = await fetch(`api.php?action=search_stories&department_id=${dId}&keyword=${encodeURIComponent(kw)}`);
    return res.json();
}

export async function saveStoryData(payload) {
    const res = await fetch('api.php?action=save_story', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    return res.json();
}

export async function getMyStories(isBin) {
    const res = await fetch(`api.php?action=get_my_stories&is_bin=${isBin}`);
    return res.json();
}

export async function moveToBin(id) {
    const res = await fetch('api.php?action=move_to_bin', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id })
    });
    return res.json();
}

export async function lockStory(id) {
    const csrfToken = window.currentUser ? window.currentUser.csrfToken : '';
    const res = await fetch('api.php?action=lock_story', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ id, csrf_token: csrfToken })
    });
    return res.json();
}

export async function unlockStory(id) {
    const csrfToken = window.currentUser ? window.currentUser.csrfToken : '';
    const data = JSON.stringify({ id, csrf_token: csrfToken });
    if (navigator.sendBeacon) {
        navigator.sendBeacon('api.php?action=unlock_story', new Blob([data], { type: 'application/json' }));
        return { success: true };
    } else {
        const res = await fetch('api.php?action=unlock_story', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: data,
            keepalive: true
        });
        return res.json();
    }
}

