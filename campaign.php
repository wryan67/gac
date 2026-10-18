<?php

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require_login();
$id = (int) ($_GET['id'] ?? 0);
$pdo = db();
$stmt = $pdo->prepare('SELECT id, layout, skill, date FROM campaigns WHERE id = ?');
$stmt->execute([$id]);
$campaign = $stmt->fetch();
if (!$campaign) {
    header('Location: index.php');
    exit;
}
$plan = zone_plan($campaign['skill'], $campaign['layout']);
$neighbor = $pdo->prepare(
    'SELECT id FROM campaigns
     WHERE date > ? OR (date = ? AND id > ?)
     ORDER BY date ASC, id ASC
     LIMIT 1'
);
$neighbor->execute([$campaign['date'], $campaign['date'], $id]);
$nextId = $neighbor->fetchColumn();
$neighbor = $pdo->prepare(
    'SELECT id FROM campaigns
     WHERE date < ? OR (date = ? AND id < ?)
     ORDER BY date DESC, id DESC
     LIMIT 1'
);
$neighbor->execute([$campaign['date'], $campaign['date'], $id]);
$previousId = $neighbor->fetchColumn();
$neighbor->closeCursor();
$squadStmt = $pdo->prepare(
    'SELECT id, zone, slot, opponent, counter, result FROM squads WHERE campaign_id = ? ORDER BY slot'
);
$squadStmt->execute([$id]);
$byZone = ['tl' => [], 'bl' => [], 'tr' => [], 'br' => []];
foreach ($squadStmt->fetchAll() as $squad) {
    $squad['id'] = (int) $squad['id'];
    $squad['slot'] = (int) $squad['slot'];
    $byZone[$squad['zone']][] = $squad;
}
$token = csrf_token();
session_write_close();

page_open('Campaign', 'campaigns');
?>
  <div class="panel panel-default panel-dns">
    <div class="panel-heading" style="display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;">
      <h3 class="page-title">
        <?= h(skill_label($campaign['skill'])) ?>
        <?= h($campaign['layout']) ?>
        <span style="font-weight:400;letter-spacing:0;"><?= h($campaign['date']) ?></span>
      </h3>
      <div class="campaign-pager">
        <?php if ($previousId): ?>
          <a class="btn btn-default" href="campaign.php?id=<?= (int) $previousId ?>">
            <i class="fa-solid fa-arrow-left"></i> previous
          </a>
        <?php endif; ?>
        <?php if ($nextId): ?>
          <a class="btn btn-default" href="campaign.php?id=<?= (int) $nextId ?>">
            next <i class="fa-solid fa-arrow-right"></i>
          </a>
        <?php endif; ?>
      </div>
    </div>
    <div class="panel-body">
      <div class="board">
        <?php
        $tabSquads = 0;
        foreach (['tl', 'bl', 'br', 'tr'] as $zone) {
            $tabSquads += count($byZone[$zone] ?? []);
        }
        $opponentTab = 1;
        $counterTab = $tabSquads + 1;
        ?>
        <?php foreach (['tl', 'bl', 'br', 'tr'] as $zone): ?>
          <section class="zone zone-<?= h($zone) ?>">
            <div class="zone-title"><span><?= h(zone_label($zone)) ?></span></div>
            <table class="table table-condensed squad-table">
                <thead>
                  <tr>
                    <th>Opponent</th>
                    <th>First counter</th>
                    <th>Second counter</th>
                    <th>Third counter</th>
                    <th>This counter</th>
                    <th>Result</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($byZone[$zone] as $squad): ?>
                    <?php
                    $kind = $zone === 'tr' ? 'ship' : 'character';
                    $result = $squad['result'] ?? '';
                    ?>
                    <tr class="squad" data-id="<?= (int) $squad['id'] ?>" data-kind="<?= h($kind) ?>">
                      <td class="name-cell">
                        <div class="lookup-wrap">
                          <div class="leader-line">
                            <span class="leader-photo opponent-photo">
                              <?php if ($squad['opponent'] !== ''): ?>
                                <img class="roster-img" alt="<?= h($squad['opponent']) ?>"
                                     src="portrait.php?kind=<?= h($kind) ?>&amp;name=<?= rawurlencode($squad['opponent']) ?>">
                              <?php else: ?>
                                <i class="fa-regular fa-id-card"></i>
                              <?php endif; ?>
                            </span>
                            <input class="form-control opponent" value="<?= h($squad['opponent']) ?>" autocomplete="off"
                                   placeholder="<?= $kind === 'ship' ? 'Capital ship' : 'Character' ?>"
                                   aria-label="Opponent" tabindex="<?= $opponentTab++ ?>">
                            <button type="button" class="leader-clear" title="Clear" aria-label="Clear opponent" tabindex="-1">
                              <i class="fa-solid fa-xmark"></i>
                            </button>
                          </div>
                        </div>
                        <p class="text-danger squad-error" style="display:none;"></p>
                      </td>
                      <td class="hist-counter"></td>
                      <td class="hist-counter"></td>
                      <td class="hist-counter"></td>
                      <td class="name-cell">
                        <div class="lookup-wrap">
                          <div class="leader-line">
                            <span class="leader-photo counter-photo">
                              <?php if ($squad['counter'] !== ''): ?>
                                <img class="roster-img" alt="<?= h($squad['counter']) ?>"
                                     src="portrait.php?kind=<?= h($kind) ?>&amp;name=<?= rawurlencode($squad['counter']) ?>">
                              <?php else: ?>
                                <i class="fa-regular fa-id-card"></i>
                              <?php endif; ?>
                            </span>
                            <input class="form-control counter" value="<?= h($squad['counter']) ?>" autocomplete="off"
                                   placeholder="<?= $kind === 'ship' ? 'Capital ship' : 'Character' ?>"
                                   aria-label="This counter" tabindex="<?= $counterTab++ ?>">
                            <button type="button" class="leader-clear" title="Clear" aria-label="Clear this counter" tabindex="-1">
                              <i class="fa-solid fa-xmark"></i>
                            </button>
                          </div>
                        </div>
                      </td>
                      <td class="result-cell">
                        <div class="btn-group result-group">
                          <button type="button" class="btn btn-default<?= $result === '' ? ' active' : '' ?>" data-result="" title="Unknown">
                            <i class="fa-solid fa-question"></i>
                          </button>
                          <button type="button" class="btn btn-default<?= $result === 'success' ? ' active' : '' ?>" data-result="success" title="Success">
                            <i class="fa-solid fa-check"></i>
                          </button>
                          <button type="button" class="btn btn-default<?= $result === 'fail' ? ' active' : '' ?>" data-result="fail" title="Fail">
                            <i class="fa-solid fa-xmark"></i>
                          </button>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
            </table>
          </section>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
<?php page_close(); ?>
<script>
var CSRF = <?= json_encode($token) ?>;
var CAMPAIGN_ID = <?= (int) $campaign['id'] ?>;
var SHIP_CAPTAINS = <?= json_encode(
    $pdo->query("SELECT name, captain FROM ships WHERE captain <> ''")->fetchAll(PDO::FETCH_KEY_PAIR),
    JSON_UNESCAPED_UNICODE
) ?>;

function field(squad, which) {
    return squad.find(which === 'counter' ? '.counter' : '.opponent');
}

function attachLookup(input) {
    var menu = $('<div class="lookup-menu" hidden></div>');
    input.after(menu);
    var timer = null;
    input.on('input', function () {
        clearTimeout(timer);
        var q = input.val();
        timer = setTimeout(function () { refresh(q); }, 60);
    });
    input.on('keydown', function (e) {
        var items = menu.find('.lookup-item');
        if (!items.length || menu.is('[hidden]')) return;
        var current = items.filter('.active');
        if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
            e.preventDefault();
            var next = e.key === 'ArrowDown' ? current.next() : current.prev();
            if (!next.length) next = e.key === 'ArrowDown' ? items.first() : items.last();
            items.removeClass('active');
            next.addClass('active');
        } else if (e.key === 'Enter') {
            e.preventDefault();
            var pick = current.length ? current : items.first();
            choose(pick.data('name'));
        } else if (e.key === 'Escape') {
            menu.attr('hidden', 'hidden');
        }
    });
    input.closest('.leader-line').find('.leader-clear').on('mousedown', function (e) {
        e.preventDefault();
        menu.attr('hidden', 'hidden');
        var had = input.val() !== '' || acceptedName(input) !== '';
        input.val('');
        input.data('saved', '');
        if (had) saveSquad(input.closest('.squad'));
    });
    input.on('blur', function () {
        menu.attr('hidden', 'hidden');
        var saved = input.data('saved') || '';
        if (input.val() === '') {
            if (saved !== '') saveSquad(input.closest('.squad'));
            return;
        }
        if (input.val() !== saved) input.val(saved);
    });

    function refresh(q) {
        if (!q) {
            menu.empty().attr('hidden', 'hidden');
            return;
        }
        var kind = input.closest('.squad').data('kind');
        $.getJSON('api.php', { api: 'suggest', kind: kind, q: q }, function (res) {
            menu.empty();
            $.each(res.matches || [], function (_, match) {
                var button = $('<button type="button" class="lookup-item"></button>');
                button.data('name', match.name);
                button.append($('<img>', {
                    alt: '',
                    src: 'portrait.php?kind=' + encodeURIComponent(kind) +
                        '&name=' + encodeURIComponent(match.name)
                }));
                var label = $('<span></span>').text(match.name);
                if (match.alias) {
                    label.append($('<span class="lookup-alias"></span>').text(' ' + match.alias));
                }
                if (match.captain) {
                    label.append($('<span class="lookup-alias"></span>').text(' ' + match.captain));
                }
                button.append(label);
                button.on('mousedown', function (e) {
                    e.preventDefault();
                    choose(match.name);
                });
                menu.append(button);
            });
            if (menu.children().length) menu.removeAttr('hidden');
            else menu.attr('hidden', 'hidden');
        });
    }

    function choose(name) {
        input.val(name);
        input.data('saved', name);
        menu.attr('hidden', 'hidden');
        input.trigger('lookup:chosen');
    }
}

function renderSuggestions(squad, suggestions) {
    var kind = squad.data('kind');
    squad.find('.hist-counter').each(function (i) {
        var cell = $(this).empty();
        var item = field(squad, 'opponent').val() ? suggestions[i] : null;
        if (!item) return;
        var button = $('<button type="button" class="hist-pick"></button>');
        button.attr('aria-label', item.name);
        button.append($('<img>', {
            'class': 'roster-img hist-portrait',
            alt: item.name,
            src: 'portrait.php?kind=' + encodeURIComponent(kind) +
                '&name=' + encodeURIComponent(item.name)
        }));
        button.on('click', function () {
            var input = field(squad, 'counter');
            input.val(item.name);
            input.data('saved', item.name);
            input.trigger('lookup:chosen');
        });
        cell.append(button);
    });
}

var portraitTip = $('<div class="portrait-tip" hidden><img alt=""><div class="portrait-tip-name"></div></div>');
var portraitTipTimer = null;
$('body').append(portraitTip);

function placePortraitTip(anchor) {
    var tip = portraitTip[0];
    var rect = anchor[0].getBoundingClientRect();
    var gap = 8;
    var width = tip.offsetWidth;
    var height = tip.offsetHeight;
    var left = rect.right + gap;
    if (left + width > window.innerWidth - 8) left = rect.left - gap - width;
    if (left < 8) left = 8;
    var top = rect.top + (rect.height / 2) - (height / 2);
    if (top + height > window.innerHeight - 8) top = window.innerHeight - 8 - height;
    if (top < 8) top = 8;
    portraitTip.css({ left: left, top: top });
}

function portraitTipText(name, captain) {
    if (!captain) return name;
    if (name.length <= 12 && captain.length <= 12) {
        return name + ' (' + captain + ')';
    }
    return name + '\n(' + captain + ')';
}

function showPortraitTip(slot) {
    var img = slot.find('.roster-img');
    var name = img.attr('alt') || slot.attr('aria-label') || '';
    if (!img.length || name === '') {
        portraitTip.attr('hidden', 'hidden');
        return;
    }
    var captain = slot.closest('.squad').data('kind') === 'ship' ? (SHIP_CAPTAINS[name] || '') : '';
    var tipImg = portraitTip.find('img');
    portraitTip.find('.portrait-tip-name').text(portraitTipText(name, captain));
    tipImg.attr('alt', name);
    if (tipImg.attr('src') !== img.attr('src')) tipImg.attr('src', img.attr('src'));
    portraitTip.removeAttr('hidden');
    placePortraitTip(slot);
    if (!tipImg[0].complete) tipImg.one('load', function () { placePortraitTip(slot); });
}

$(document).on('mouseenter', '.opponent-photo, .counter-photo, .hist-pick', function () {
    var slot = $(this);
    clearTimeout(portraitTipTimer);
    portraitTip.attr('hidden', 'hidden');
    portraitTipTimer = setTimeout(function () {
        portraitTipTimer = null;
        showPortraitTip(slot);
    }, 333);
});
$(document).on('mouseleave', '.opponent-photo, .counter-photo, .hist-pick', function () {
    clearTimeout(portraitTipTimer);
    portraitTipTimer = null;
    portraitTip.attr('hidden', 'hidden');
});

function showLeader(squad, which) {
    var name = field(squad, which).val();
    var slot = squad.find(which === 'counter' ? '.counter-photo' : '.opponent-photo');
    slot.empty();
    if (!name) {
        slot.append('<i class="fa-regular fa-id-card"></i>');
        return;
    }
    var img = $('<img>', {
        'class': 'roster-img',
        alt: name,
        src: 'portrait.php?kind=' + encodeURIComponent(squad.data('kind')) +
            '&name=' + encodeURIComponent(name)
    });
    img.on('error', function () {
        slot.empty().append('<i class="fa-regular fa-id-card"></i>');
    });
    slot.append(img);
}

function acceptedName(input) {
    var value = input.data('accepted');
    return value == null ? '' : String(value);
}

function leaderTaken(squad, which, name) {
    if (!name) return false;
    var needle = name.toLocaleLowerCase();
    var taken = false;
    $('.squad').each(function () {
        var other = $(this);
        if (String(other.data('id')) === String(squad.data('id'))) return;
        if (acceptedName(field(other, which)).toLocaleLowerCase() === needle) taken = true;
    });
    return taken;
}

function duplicateMessages(squad, opponent, counter) {
    var messages = [];
    if (leaderTaken(squad, 'opponent', opponent)) {
        messages.push(opponent + ' is already an opponent in this campaign.');
    }
    if (leaderTaken(squad, 'counter', counter)) {
        messages.push(counter + ' is already used as this counter in this campaign.');
    }
    return messages;
}

function restoreAccepted(squad) {
    ['opponent', 'counter'].forEach(function (which) {
        var input = field(squad, which);
        var prev = acceptedName(input);
        input.val(prev);
        input.data('saved', prev);
    });
    showLeader(squad, 'opponent');
    showLeader(squad, 'counter');
}

function saveSquad(squad) {
    var early = duplicateMessages(squad, field(squad, 'opponent').val(), field(squad, 'counter').val());
    if (early.length) {
        restoreAccepted(squad);
        swgohSay(early.join('\n'));
        return;
    }
    showLeader(squad, 'opponent');
    showLeader(squad, 'counter');
    squad.data('saveQueued', true);
    if (squad.data('saving')) return;
    function run() {
        squad.data('saving', true);
        squad.data('saveQueued', false);
        var result = squad.find('.result-group .active').data('result');
        var opponent = field(squad, 'opponent').val();
        var counter = field(squad, 'counter').val();
        var problems = duplicateMessages(squad, opponent, counter);
        if (problems.length) {
            squad.data('saving', false);
            restoreAccepted(squad);
            swgohSay(problems.join('\n'));
            if (squad.data('saveQueued')) run();
            return;
        }
        var rollback = {
            opponent: acceptedName(field(squad, 'opponent')),
            counter: acceptedName(field(squad, 'counter'))
        };
        field(squad, 'opponent').data('accepted', opponent);
        field(squad, 'counter').data('accepted', counter);
        squad.find('.squad-error').hide();
        $.post('api.php?api=squad_save', {
            csrf: CSRF,
            id: squad.data('id'),
            opponent: opponent,
            counter: counter,
            result: result == null ? '' : result
        }, null, 'json').done(function (res) {
            if (squad.data('saveQueued')) return;
            field(squad, 'opponent').data('saved', opponent);
            field(squad, 'counter').data('saved', counter);
            renderSuggestions(squad, (res && res.suggestions) || []);
        }).fail(function (xhr) {
            field(squad, 'opponent').data('accepted', rollback.opponent);
            field(squad, 'counter').data('accepted', rollback.counter);
            if (squad.data('saveQueued')) return;
            restoreAccepted(squad);
            var msg = (xhr.responseJSON && xhr.responseJSON.error) || 'Could not save this squad.';
            squad.find('.squad-error').text(msg).show();
            swgohSay(msg);
        }).always(function () {
            squad.data('saving', false);
            if (squad.data('saveQueued')) run();
        });
    }
    run();
}

$('.squad').each(function () {
    var squad = $(this);
    field(squad, 'opponent').data('saved', field(squad, 'opponent').val());
    field(squad, 'counter').data('saved', field(squad, 'counter').val());
    field(squad, 'opponent').data('accepted', field(squad, 'opponent').val());
    field(squad, 'counter').data('accepted', field(squad, 'counter').val());
    attachLookup(field(squad, 'opponent'));
    attachLookup(field(squad, 'counter'));
    field(squad, 'opponent').on('lookup:chosen', function () { saveSquad(squad); });
    field(squad, 'counter').on('lookup:chosen', function () { saveSquad(squad); });
    squad.find('.result-group').on('click', 'button', function () {
        squad.find('.result-group button').removeClass('active');
        $(this).addClass('active');
        saveSquad(squad);
    });
    if (field(squad, 'opponent').val()) {
        $.getJSON('api.php', {
            api: 'counters',
            campaign: CAMPAIGN_ID,
            opponent: field(squad, 'opponent').val()
        }, function (res) {
            renderSuggestions(squad, (res && res.suggestions) || []);
        });
    }
});
</script>
</body>
</html>
