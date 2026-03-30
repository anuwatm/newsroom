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
