/**
 * Akatsuki - Manga/Webtoon Platform
 * Site-wide JavaScript (loaded by footer.php)
 */

document.addEventListener('DOMContentLoaded', function () {
    // ── Mobile menu toggle ─────────────────────────────
    const mobileMenuBtn = document.querySelector('.mobile-menu-btn');
    const header = document.querySelector('.main-header');
    if (mobileMenuBtn && header) {
        mobileMenuBtn.addEventListener('click', function () {
            this.classList.toggle('active');
            header.classList.toggle('menu-open');
        });
    }

    // ── Smooth scroll for in-page anchors ──────────────
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            const targetId = this.getAttribute('href');
            if (targetId.length > 1) {
                const target = document.querySelector(targetId);
                if (target) {
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });

    // ── Reader: ← / → keys move between chapters ───────
    if (document.querySelector('.reader-container')) {
        document.addEventListener('keydown', function (e) {
            if (['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement.tagName)) return;
            const prev = document.querySelector('[data-nav="prev"]');
            const next = document.querySelector('[data-nav="next"]');
            if (e.key === 'ArrowLeft' && prev) window.location.href = prev.href;
            if (e.key === 'ArrowRight' && next) window.location.href = next.href;
        });
    }

    // ── Prevent double-submits (skips forms whose confirm() was cancelled) ──
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function (e) {
            if (e.defaultPrevented || form.dataset.noSpinner !== undefined) return;
            const submitBtn = form.querySelector('button[type="submit"]');
            if (!submitBtn) return;
            // Disable on the next tick so the button's own name/value is still sent
            setTimeout(() => {
                submitBtn.disabled = true;
                submitBtn.dataset.label = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner"></span> Processing...';
            }, 0);
        });
    });
    // Re-enable buttons when the page is restored from the back/forward cache
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('button[data-label]').forEach(b => {
            b.disabled = false;
            b.innerHTML = b.dataset.label;
        });
    });

    // ── Auto-hide alerts after 6 seconds ───────────────
    document.querySelectorAll('.alert:not(.alert-sticky)').forEach(alert => {
        setTimeout(() => {
            alert.style.transition = 'opacity 0.5s ease';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 6000);
    });

    // ── Genre dropdown on touch screens ────────────────
    document.querySelectorAll('.nav-dropdown > .nav-link').forEach(link => {
        link.addEventListener('click', function (e) {
            if (window.innerWidth <= 900) {
                e.preventDefault();
                this.parentElement.classList.toggle('open');
            }
        });
    });

    // Images that already failed before this script ran
    document.querySelectorAll('img').forEach(img => {
        if (img.complete && img.naturalWidth === 0 && img.getAttribute('src')) useFallback(img);
    });

    // ── Close any open modal with Escape ───────────────
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        document.querySelectorAll('.modal-overlay').forEach(m => m.style.display = 'none');
        document.body.style.overflow = '';
    });
});

// ── Broken images fall back to the placeholder (only once) ──
function useFallback(img) {
    if (img.dataset.fallback || img.hasAttribute('onerror') || img.dataset.noFallback !== undefined) return;
    img.dataset.fallback = '1';
    img.src = 'assets/thumbnails/placeholder.webp';
}
document.addEventListener('error', e => {
    if (e.target && e.target.tagName === 'IMG') useFallback(e.target);
}, true);

// Scroll to top helper
function scrollToTop() {
    window.scrollTo({ top: 0, behavior: 'smooth' });
}
