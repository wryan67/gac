<?php

function page_open(string $title, string $active): void
{
    $profile = current_user();
    theme_cookie($profile['theme']);
    $token = csrf_token();
    $user = (string) $profile['userid'];
    $theme = $profile['theme'];
    ?>
<!DOCTYPE html>
<html lang="en" class="theme-<?= h($theme) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> - SWGOH</title>
<link rel="stylesheet" href="assets/vendor/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/vendor/css/jquery.bootgrid.min.css">
<link rel="stylesheet" href="assets/vendor/css/fontawesome.min.css">
<link rel="stylesheet" href="assets/vendor/css/source-sans-pro.css">
<style>
    body { font-family: 'Source Sans Pro', Arial, sans-serif; background: #f4f4f4; padding-top: 70px; }
    .navbar-dns { background: #35312f; border: 0; border-radius: 0; }
    .navbar-dns .navbar-brand { color: #fff; font-weight: 900; letter-spacing: 0.1em; text-transform: uppercase; }
    .navbar-dns .navbar-toggle { border-color: #777; }
    .navbar-dns .navbar-toggle .icon-bar { background: #fff; }
    .navbar-dns .navbar-nav > li > a { color: #ddd; }
    .navbar-dns .navbar-nav > li > a:hover { color: #fff; background: #4a4441; }
    .navbar-dns .navbar-nav > .active > a,
    .navbar-dns .navbar-nav > .active > a:hover { color: #fff; background: #337ab7; }
    .panel-dns { border-top: 3px solid #337ab7; }
    .page-title { font-weight: 900; text-transform: uppercase; letter-spacing: 0.08em; font-size: 1.3em; margin: 0; }
    .cmd-btn { cursor: pointer; border: 0; background: none; padding: 2px 8px; font-size: 1.1em; color: #35312f; }
    .cmd-btn:hover { color: #337ab7; }
    .cmd-btn.cmd-danger:hover { color: #c0392b; }
    #campaignGrid tbody tr { cursor: pointer; }
    .heading-tools { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
    .filter-label { font-weight: 700; color: #555; margin-right: 4px; }
    .filter-group .btn { border-color: #ccc; color: #55504d; font-weight: 600; }
    .filter-group .btn:hover { background: #eceef0; }
    .filter-group .btn.active,
    .filter-group .btn.active:hover,
    .filter-group .btn.active:focus {
        background: #337ab7; border-color: #2e6da4; color: #fff; box-shadow: none;
    }
    .roster-img { width: 48px; height: 48px; object-fit: contain; background: #16181d; border-radius: 4px; }
    .alias-cell { display: flex; align-items: center; gap: 6px; }
    .alias-form { display: flex; align-items: center; gap: 4px; }
    .alias-form[hidden] { display: none; }
    .alias-form input { width: 12rem; }
    .capital-yes { color: #1f4f8a; }
    .campaign-pager { display: flex; gap: 8px; }
    .board { display: flex; flex-direction: column; gap: 8px; }
    .zone {
        display: flex; align-items: stretch; gap: 0; margin: 0; padding: 0;
        border: 2px solid #337ab7; border-radius: 8px;
    }
    .zone-title {
        flex: 0 0 auto; display: flex; align-items: center; justify-content: center;
        margin: 0; padding: 6px 8px; background: #337ab7; color: #fff;
        border-radius: 6px 0 0 6px;
    }
    .zone .squad-table { margin: 6px; }
    .zone-title span {
        writing-mode: vertical-rl; transform: rotate(180deg);
        font-size: 18px; font-weight: 700; line-height: 1; letter-spacing: 0.04em; white-space: nowrap;
    }
    #appDialogText { margin: 0; white-space: pre-line; }
    #appDialog .modal-dialog { margin-top: 90px; }
    table.squad-table { margin-bottom: 0; width: max-content; max-width: 100%; }
    .squad-table th, .squad-table td { vertical-align: middle; padding: 3px 6px; white-space: nowrap; }
    .squad-table .hist-counter, .squad-table .result-cell, .squad-table .name-cell { width: 1%; white-space: nowrap; }
    .squad-table .hist-counter, .squad-table .result-cell { text-align: center; }
    .squad-table .leader-photo { flex: 0 0 32px; width: 32px; height: 32px; font-size: 16px; }
    .squad-table .roster-img, .squad-table .hist-portrait { width: 32px; height: 32px; }
    .squad-table .leader-line .form-control { flex: 0 0 236px; width: 236px; height: 32px; padding: 4px 26px 4px 8px; }
    .leader-clear {
        position: absolute; right: 1px; z-index: 2; top: 50%; transform: translateY(-50%);
        width: 24px; height: 24px; padding: 0; border: 0; background: transparent;
        color: #777; font-size: 14px; line-height: 1; cursor: pointer;
        -webkit-appearance: none; appearance: none;
    }
    html.theme-light .leader-clear:hover,
    html.theme-light .leader-clear:focus { color: #222; background: transparent; }
    html.theme-dark .leader-clear { color: #9aabbf; }
    html.theme-dark .leader-clear:hover,
    html.theme-dark .leader-clear:focus { color: #fff; background: transparent; }
    .squad-table .result-group { display: inline-flex; flex-wrap: nowrap; vertical-align: middle; }
    .squad-table .result-group > .btn { float: none; padding: 4px 8px; line-height: 1.2; }
    .hist-portrait { width: 32px; height: 32px; }
    .hist-pick {
        display: inline-block; padding: 0; border: 0; background: transparent;
        line-height: 0; cursor: pointer; border-radius: 4px;
    }
    .hist-pick:focus { outline: 2px solid #0089ea; outline-offset: 1px; }
    .squad-error { margin: 4px 0 0; }
    .lookup-wrap { position: relative; }
    .leader-line { display: flex; align-items: center; gap: 8px; position: relative; }
    .leader-line .form-control { flex: 1; min-width: 0; }
    .leader-photo {
        flex: 0 0 48px; width: 48px; height: 48px; overflow: hidden;
        display: flex; align-items: center; justify-content: center;
        background: #16181d; color: #c5ccd4; border-radius: 4px; font-size: 22px;
    }
    .leader-photo .roster-img { display: block; }
    .portrait-tip {
        position: fixed; z-index: 80; display: flex; flex-direction: column; align-items: center;
        gap: 6px; padding: 8px; margin: 0; pointer-events: none;
        background: #081427; color: #E7EEF6; border: 1px solid #337AB7; border-radius: 6px;
        box-shadow: 0 8px 24px rgba(0,0,0,.18);
    }
    .portrait-tip[hidden] { display: none !important; }
    .portrait-tip img {
        display: block; width: auto; height: auto; max-width: none; max-height: none;
        background: #081427; border-radius: 4px;
    }
    .portrait-tip-name {
        color: #E7EEF6; font-weight: 700; font-size: 14px; line-height: 1.2;
        text-align: center; white-space: pre-line;
    }
    .leader-line .lookup-menu { left: 40px; }
    .lookup-menu {
        position: absolute; z-index: 20; left: 0; right: 0; top: 100%;
        background: #fff; border: 1px solid #ccc; border-radius: 0 0 4px 4px;
        max-height: 240px; overflow: auto; box-shadow: 0 6px 16px rgba(0,0,0,.12);
    }
    .lookup-item {
        display: flex; align-items: center; gap: 8px; width: 100%; text-align: left;
        border: 0; background: #fff; padding: 4px 8px;
    }
    .lookup-item img {
        flex: 0 0 32px; width: 32px; height: 32px; object-fit: contain;
        background: #16181d; border-radius: 4px;
    }
    .lookup-item:hover, .lookup-item.active { background: #eef5fb; }
    .lookup-alias { color: #777; margin-left: 8px; }
    .bootgrid-header .search.roster-lookup { width: 280px; position: relative; }
    .bootgrid-header .search.roster-lookup .input-group { position: relative; }
    .bootgrid-header .search.roster-lookup .search-field { padding-right: 28px; }
    .bootgrid-header .search.roster-lookup .lookup-menu { left: 0; right: 0; z-index: 40; }
    .capital-only {
        float: left; display: inline-flex; align-items: center; gap: 8px;
        margin: 6px 12px 0 0; font-weight: 600; cursor: pointer; color: #555;
    }
    .capital-only input { width: 18px; height: 18px; margin: 0; accent-color: #0089ea; cursor: pointer; }
    .suggest-row { margin-bottom: 8px; }
    .profile-form { max-width: 28rem; }
    .result-group .btn.active,
    .result-group .btn.active:hover,
    .result-group .btn.active:focus {
        background: #0089ea; border-color: #007fd9; color: #fff;
        background-image: none; box-shadow: none;
    }
</style>
<link rel="stylesheet" href="swgoh.css">
</head>
<body>
<nav class="navbar navbar-dns navbar-fixed-top">
  <div class="container-fluid">
    <div class="navbar-header">
      <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#swgohNav">
        <span class="icon-bar"></span><span class="icon-bar"></span><span class="icon-bar"></span>
      </button>
      <a class="navbar-brand" href="index.php">SWGOH</a>
    </div>
    <div class="collapse navbar-collapse" id="swgohNav">
      <ul class="nav navbar-nav">
        <li class="<?= $active === 'campaigns' ? 'active' : '' ?>">
          <a href="index.php"><i class="fa-solid fa-flag"></i> Campaigns</a></li>
        <li class="<?= $active === 'characters' ? 'active' : '' ?>">
          <a href="roster.php?kind=characters"><i class="fa-solid fa-user"></i> Characters</a></li>
        <li class="<?= $active === 'ships' ? 'active' : '' ?>">
          <a href="roster.php?kind=ships"><i class="fa-solid fa-rocket"></i> Ships</a></li>
      </ul>
      <ul class="nav navbar-nav navbar-right">
        <li class="<?= $active === 'profile' ? 'active' : '' ?>">
          <a href="profile.php"><i class="fa-regular fa-user"></i> <?= h($user) ?></a></li>
        <li><a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
      </ul>
    </div>
  </div>
</nav>
<div class="container-fluid">
    <?php
}

function page_close(): void
{
    ?>
</div>
<div class="modal fade" id="appDialog" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-body">
        <p id="appDialogText"></p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-default" id="appDialogCancel" data-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-primary" id="appDialogOk">OK</button>
      </div>
    </div>
  </div>
</div>
<script src="assets/vendor/js/jquery.min.js"></script>
<script src="assets/vendor/js/bootstrap.min.js"></script>
<script src="assets/vendor/js/jquery.bootgrid.min.js"></script>
<script>
(function () {
    var pending = null;
    window.swgohSay = function (message) {
        pending = null;
        $('#appDialogText').text(message);
        $('#appDialogCancel').hide();
        $('#appDialogOk').text('OK').removeClass('btn-danger').addClass('btn-primary');
        $('#appDialog').modal('show');
    };
    window.swgohConfirm = function (message, onYes) {
        pending = onYes;
        $('#appDialogText').text(message);
        $('#appDialogCancel').show();
        $('#appDialogOk').text('Delete').removeClass('btn-primary').addClass('btn-danger');
        $('#appDialog').modal('show');
    };
    $('#appDialogOk').on('click', function () {
        var go = pending;
        pending = null;
        $('#appDialog').one('hidden.bs.modal', function () {
            if (go) go();
        });
        $('#appDialog').modal('hide');
    });
    $('#appDialog').on('hidden.bs.modal', function () {
        pending = null;
    });
    $('#appDialog').on('shown.bs.modal', function () {
        $('#appDialogOk').trigger('focus');
    });
})();
</script>
    <?php
}
