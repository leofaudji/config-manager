<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/Parsedown.php';
require_once __DIR__ . '/../includes/header.php';

$changelog_content = "### Error\nChangelog file (`CHANGELOG.md`) not found or could not be read.";
$changelog_path = PROJECT_ROOT . '/CHANGELOG.md';

if (file_exists($changelog_path)) {
    $markdown_content = file_get_contents($changelog_path);
    $Parsedown = new Parsedown();
    $changelog_content = $Parsedown->text($markdown_content);
}
?>

<style>
/* Custom styles for the changelog page to improve readability */
.changelog-content h1, .changelog-content h2 {
    border-bottom: 1px solid var(--cf-border-color);
    padding-bottom: 0.3em;
    margin-top: 1.5em;
}
.changelog-content h2 { margin-top: 2em; }
.changelog-content h3 {
    margin-top: 1.5em;
    color: var(--cf-text-muted-color);
    font-size: 1.2rem;
    font-weight: 600;
}
.changelog-content ul { 
    list-style-type: disc; 
    padding-left: 2em; 
}
.changelog-content ul ul {
    list-style-type: circle;
    margin-top: 0.5em;
    padding-left: 1.5em;
}
.changelog-content li { 
    margin-bottom: 0.6em; 
    line-height: 1.6;
}
.changelog-content code {
    background-color: var(--cf-table-striped-bg);
    padding: 0.2em 0.4em;
    border-radius: 3px;
    font-size: 85%;
}
.changelog-content strong {
    font-weight: 600;
    color: var(--cf-text-color);
}
</style>

<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
    <h1 class="h2"><i class="bi bi-card-list"></i> Changelog</h1>
</div>

<div class="card">
    <div class="card-body changelog-content">
        <?= $changelog_content ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>