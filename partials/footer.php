<?php
// ============ partials/footer.php ============
?>

<footer style="background: var(--secondary); color: white; padding: 0.5rem 0; margin-top: 2rem; font-size: 0.75rem;">
    <div class="container text-center">
        <small>
            © <?= date('Y') ?> <?= escape(get_setting('site_name', 'ระบบคีย์หวย')) ?> v1.0
        </small>
    </div>
</footer>

<!-- JavaScript -->
<script src="/assets/js/app.js"></script>

</body>
</html>