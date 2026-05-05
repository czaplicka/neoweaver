document.addEventListener('DOMContentLoaded', function () {
    const select = document.getElementById('ach-char-select');
    if (select && select.form) {
        select.addEventListener('change', function () {
            select.form.submit();
        });
    }

    if (window.lucide && typeof window.lucide.createIcons === 'function') {
        window.lucide.createIcons();
    }
});
