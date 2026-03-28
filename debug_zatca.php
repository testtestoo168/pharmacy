<?php
session_start();
require_once 'includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
}
?>
<form method="POST">
<?= csrfField() ?>
<button type="submit" name="zatca_compliance_check" value="1">Test</button>
</form>
<?php
session_start();
require_once 'includes/config.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo "<pre>";
    print_r($_POST);
    echo "</pre>";
}
?>
<form method="POST">
<?= csrfField() ?>
<button type="submit" name="zatca_compliance_check" value="1">Test</button>
</form>
