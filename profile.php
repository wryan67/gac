<?php

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require_login();
$profile = current_user();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $email = trim($_POST['email'] ?? '');
    $theme = ($_POST['theme'] ?? '') === 'dark' ? 'dark' : 'light';
    if (strlen($email) > 200 || ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false)) {
        $error = 'Enter a valid email address.';
        $profile['email'] = $email;
        $profile['theme'] = $theme;
    } else {
        $stmt = db()->prepare('UPDATE users SET email = ?, theme = ? WHERE userid = ?');
        $stmt->execute([$email, $theme, $_SESSION['userid']]);
        theme_cookie($theme);
        header('Location: profile.php?saved=1', true, 303);
        exit;
    }
}

$saved = isset($_GET['saved']);
session_write_close();

page_open('Profile', 'profile');
?>
  <div class="panel panel-default panel-dns">
    <div class="panel-heading">
      <h3 class="page-title">Profile</h3>
    </div>
    <div class="panel-body">
      <?php if ($saved): ?>
        <div class="alert alert-success">Saved.</div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
      <?php endif; ?>
      <form method="post" action="profile.php" class="profile-form">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <div class="form-group">
          <label for="email">Email address</label>
          <input class="form-control" id="email" name="email" type="email" maxlength="200"
                 autocomplete="email" value="<?= h($profile['email']) ?>">
        </div>
        <div class="form-group">
          <label>Theme</label>
          <div>
            <label class="radio-inline">
              <input type="radio" name="theme" value="light"<?= $profile['theme'] === 'light' ? ' checked' : '' ?>>
              Light
            </label>
            <label class="radio-inline">
              <input type="radio" name="theme" value="dark"<?= $profile['theme'] === 'dark' ? ' checked' : '' ?>>
              Dark
            </label>
          </div>
        </div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk"></i> Save</button>
      </form>
    </div>
  </div>
<?php page_close(); ?>
</body>
</html>
