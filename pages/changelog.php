<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/ChangelogParser.php'; // Menggunakan parser baru
require_once __DIR__ . '/../includes/header.php';

$changelog_content = "### Error\nChangelog file (`CHANGELOG.md`) not found or could not be read.";
$changelog_path = PROJECT_ROOT . '/CHANGELOG.md'; // NOSONAR

if (file_exists($changelog_path)) {
    $markdown_content = file_get_contents($changelog_path);
    $parsed_changelog = ChangelogParser::parse($markdown_content);
    $changelog_content = ChangelogParser::renderHtml($parsed_changelog);
}
?>

<style>
    #changelogAccordion .accordion-item {
        background-color: transparent;
        border: none;
        border-bottom: 1px solid var(--bs-border-color);
    }
    #changelogAccordion .accordion-item:last-child {
        border-bottom: none;
    }
    #changelogAccordion .accordion-button {
        background-color: transparent;
        font-size: 1.25rem;
        font-weight: 600;
        padding: 1.25rem 1.5rem;
    }
    #changelogAccordion .accordion-button:not(.collapsed) {
        color: var(--bs-primary);
        background-color: transparent;
        box-shadow: none;
    }
    #changelogAccordion .accordion-button:focus {
        box-shadow: none;
        border-color: rgba(0,0,0,.125);
    }
    .changelog-content h3 {
        font-size: 0.9rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        padding: 0.3em 0.8em;
        border-radius: 999px;
        display: inline-block;
        color: #fff;
    }
    .changelog-content h3.category-added { background-color: var(--bs-success); }
    .changelog-content h3.category-changed { background-color: var(--bs-info); }
    .changelog-content h3.category-fixed { background-color: var(--bs-warning); color: #000 !important; }
    .changelog-content h3.category-removed { background-color: var(--bs-danger); }
    .changelog-content h3.category-security { background-color: var(--bs-dark); }
    .changelog-content ul {
        list-style-type: none;
        padding-left: 1.25rem;
    }
    .changelog-content li {
        padding-left: 1.25rem;
        margin-bottom: 0.8rem;
        position: relative;
        line-height: 1.6;
    }
    .changelog-content li::before {
        content: '•';
        position: absolute;
        left: 0;
        color: var(--bs-primary);
        font-weight: bold;
    }
    .changelog-content code {
        background-color: var(--bs-secondary-bg);
        padding: 0.2em 0.4em;
        border-radius: 5px;
        font-size: 87.5%;
        border: 1px solid var(--bs-border-color);
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