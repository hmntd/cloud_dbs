export async function apiRequest(url, options = {}) {
    const headers = {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(options.headers || {}),
    };

    const response = await fetch(url, {
        ...options,
        headers,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = data?.errors
            ? Object.values(data.errors).flat()[0]
            : null;
        throw new Error(firstError || data?.message || `Request failed (${response.status})`);
    }

    return data;
}

