    </main>

    <!-- Print-Only Page Footer -->
    <div class="print-page-footer" style="display:none;">
        <?= htmlspecialchars($company['company_name'] ?? t('app_name')) ?> — <?= date('Y-m-d H:i') ?>
    </div>

    <!-- حقوق النظام -->
    <div class="no-print" style="text-align:center;padding:15px 10px;margin-top:20px;border-top:1px solid #e2e8f0;color:#64748b;font-size:12px;">
        <div style="margin-bottom:4px;"><i class="fas fa-code"></i> <?= t('footer.system_name') ?></div>
        <div style="font-weight:600;color:#1a2744;"><?= t('footer.developed_by') ?></div>
    </div>
</div><!-- end main-wrapper -->

<script>
function formatNumber(num) {
    return parseFloat(num).toFixed(2);
}

// Sidebar functions
function openMobileSidebar() {
    document.getElementById('sidebar').classList.add('active');
    document.getElementById('sidebarOverlay').classList.add('active');
}
function closeMobileSidebar() {
    document.getElementById('sidebar').classList.remove('active');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// Overlay click = close
document.getElementById('sidebarOverlay').addEventListener('click', closeMobileSidebar);

// Floating button (mobile only via CSS)
document.getElementById('mobMenuToggle').addEventListener('click', function() {
    if (document.getElementById('sidebar').classList.contains('active')) {
        closeMobileSidebar();
    } else {
        openMobileSidebar();
    }
});

// Top bar toggle — smart: desktop = collapse, mobile = slide
document.getElementById('topBarToggle').addEventListener('click', function() {
    if (window.innerWidth > 1024) {
        // Desktop: collapse sidebar
        document.body.classList.toggle('sidebar-collapsed');
    } else {
        // Mobile: slide sidebar
        if (document.getElementById('sidebar').classList.contains('active')) {
            closeMobileSidebar();
        } else {
            openMobileSidebar();
        }
    }
});

// Close sidebar on nav click (mobile only)
document.querySelectorAll('.sidebar-link').forEach(function(link) {
    link.addEventListener('click', function() {
        if (window.innerWidth <= 1024) closeMobileSidebar();
    });
});
</script>
</body>
</html>
