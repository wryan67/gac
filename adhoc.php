<?php

require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

require_login();
$pdo = db();
$captains = $pdo->query("SELECT name, captain FROM ships WHERE captain <> ''")->fetchAll(PDO::FETCH_KEY_PAIR);
session_write_close();

page_open('Adhoc counter', 'adhoc');
?>
  <div class="panel panel-default panel-dns">
    <div class="panel-heading">
      <h3 class="page-title">Adhoc counter</h3>
    </div>
    <div class="panel-body">
      <form class="board" autocomplete="off" onsubmit="return false;">
        <?php
        $rows = [
            ['label' => 'Squad', 'kind' => 'character'],
            ['label' => 'Ship', 'kind' => 'ship'],
        ];
        $opponentTab = 1;
        $counterTab = count($rows) + 1;
        foreach ($rows as $row):
            $kind = $row['kind'];
        ?>
          <section class="zone">
            <div class="zone-title"><span><?= h($row['label']) ?></span></div>
            <table class="table table-condensed squad-table">
              <thead>
                <tr>
                  <th>Opponent</th>
                  <th>First counter</th>
                  <th>Second counter</th>
                  <th>Third counter</th>
                  <th>This counter</th>
                </tr>
              </thead>
              <tbody>
                <tr class="squad" data-kind="<?= h($kind) ?>">
                  <td class="name-cell">
                    <div class="lookup-wrap">
                      <div class="leader-line">
                        <span class="leader-photo opponent-photo"><i class="fa-regular fa-id-card"></i></span>
                        <input class="form-control opponent" value="" autocomplete="off"
                               placeholder="<?= $kind === 'ship' ? 'Capital ship' : 'Character' ?>"
                               aria-label="Opponent" tabindex="<?= $opponentTab++ ?>">
                        <button type="button" class="leader-clear" title="Clear" aria-label="Clear opponent" tabindex="-1">
                          <i class="fa-solid fa-xmark"></i>
                        </button>
                      </div>
                    </div>
                  </td>
                  <td class="hist-counter"></td>
                  <td class="hist-counter"></td>
                  <td class="hist-counter"></td>
                  <td class="name-cell">
                    <div class="lookup-wrap">
                      <div class="leader-line">
                        <span class="leader-photo counter-photo"><i class="fa-regular fa-id-card"></i></span>
                        <input class="form-control counter" value="" autocomplete="off"
                               placeholder="<?= $kind === 'ship' ? 'Capital ship' : 'Character' ?>"
                               aria-label="This counter" tabindex="<?= $counterTab++ ?>">
                        <button type="button" class="leader-clear" title="Clear" aria-label="Clear this counter" tabindex="-1">
                          <i class="fa-solid fa-xmark"></i>
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
              </tbody>
            </table>
          </section>
        <?php endforeach; ?>
      </form>
    </div>
  </div>
<?php page_close(); ?>
<script>
var SHIP_CAPTAINS = <?= json_encode($captains, JSON_UNESCAPED_UNICODE) ?>;

function field(squad, which) {
    return squad.find(which === 'counter' ? '.counter' : '.opponent');
}

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

function renderSuggestions(squad, suggestions) {
    var kind = squad.data('kind');
    squad.find('.hist-counter').each(function (i) {
        var cell = $(this).empty();
        var item = field(squad, 'opponent').val() ? suggestions[i] : null;
        if (!item) return;
        var button = $('<button type="button" class="hist-pick" tabindex="-1"></button>');
        button.attr('aria-label', item.name + ', ' + item.count + (item.count === 1 ? ' win' : ' wins'));
        button.append($('<img>', {
            'class': 'roster-img hist-portrait',
            alt: item.name,
            src: 'portrait.php?kind=' + encodeURIComponent(kind) +
                '&name=' + encodeURIComponent(item.name)
        }));
        button.append($('<span class="hist-wins"></span>').text(item.count));
        button.on('click', function () {
            var input = field(squad, 'counter');
            input.val(item.name);
            input.data('saved', item.name);
            showLeader(squad, 'counter');
        });
        cell.append(button);
    });
}

function loadCounters(squad) {
    var opponent = field(squad, 'opponent').val();
    if (!opponent) {
        renderSuggestions(squad, []);
        return;
    }
    $.getJSON('api.php', {
        api: 'counters',
        layout: '5v5',
        kind: squad.data('kind'),
        opponent: opponent
    }, function (res) {
        if (field(squad, 'opponent').val() !== opponent) return;
        renderSuggestions(squad, (res && res.suggestions) || []);
    });
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
        input.val('');
        input.data('saved', '');
        var squad = input.closest('.squad');
        showLeader(squad, input.hasClass('counter') ? 'counter' : 'opponent');
        if (input.hasClass('opponent')) loadCounters(squad);
    });
    input.on('blur', function () {
        menu.attr('hidden', 'hidden');
        var saved = input.data('saved') || '';
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
                var button = $('<button type="button" class="lookup-item" tabindex="-1"></button>');
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

var portraitTip = $('<div class="portrait-tip" hidden><img alt=""><div class="portrait-tip-name"></div></div>');
var portraitTipTimer = null;
var portraitTipHideTimer = null;
var portraitTipAnchor = null;
var portraitTipIgnore = null;
$('body').append(portraitTip);

function iosDevice() {
    return /iPad|iPhone|iPod/.test(navigator.userAgent)
        || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
}

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
    if (name.length <= 12 && captain.length <= 12) return name + ' (' + captain + ')';
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
    portraitTipAnchor = slot[0];
    portraitTip.css('pointer-events', iosDevice() ? 'auto' : 'none');
    placePortraitTip(slot);
    if (!tipImg[0].complete) tipImg.one('load', function () { placePortraitTip(slot); });
    clearTimeout(portraitTipHideTimer);
    portraitTipHideTimer = null;
    if (iosDevice()) {
        portraitTipHideTimer = setTimeout(function () {
            portraitTipHideTimer = null;
            portraitTip.attr('hidden', 'hidden');
            portraitTipAnchor = null;
        }, 10000);
    }
}

$(document).on('mouseenter', '.opponent-photo, .counter-photo, .hist-pick', function () {
    if (portraitTipIgnore === this) {
        portraitTipIgnore = null;
        return;
    }
    var slot = $(this);
    clearTimeout(portraitTipTimer);
    clearTimeout(portraitTipHideTimer);
    portraitTipHideTimer = null;
    portraitTip.attr('hidden', 'hidden');
    portraitTipTimer = setTimeout(function () {
        portraitTipTimer = null;
        showPortraitTip(slot);
    }, 333);
});
$(document).on('mouseleave', '.opponent-photo, .counter-photo, .hist-pick', function () {
    if (iosDevice()) return;
    clearTimeout(portraitTipTimer);
    clearTimeout(portraitTipHideTimer);
    portraitTipTimer = null;
    portraitTipHideTimer = null;
    portraitTipAnchor = null;
    portraitTip.attr('hidden', 'hidden');
});
$(document).on('touchstart', function (event) {
    if (!iosDevice() || portraitTip[0].hidden) return;
    var touch = event.originalEvent.touches && event.originalEvent.touches[0];
    if (!touch) return;
    var rect = portraitTip[0].getBoundingClientRect();
    if (touch.clientX >= rect.left && touch.clientX <= rect.right &&
        touch.clientY >= rect.top && touch.clientY <= rect.bottom) {
        return;
    }
    var portrait = $(event.target).closest('.opponent-photo, .counter-photo, .hist-pick')[0];
    if (portrait && portrait !== portraitTipAnchor) return;
    portraitTipIgnore = portrait || null;
    if (portrait) {
        setTimeout(function () {
            if (portraitTipIgnore === portrait) portraitTipIgnore = null;
        }, 700);
    }
    clearTimeout(portraitTipTimer);
    clearTimeout(portraitTipHideTimer);
    portraitTipTimer = null;
    portraitTipHideTimer = null;
    portraitTipAnchor = null;
    portraitTip.attr('hidden', 'hidden');
});

$('.squad').each(function () {
    var squad = $(this);
    field(squad, 'opponent').data('saved', '');
    field(squad, 'counter').data('saved', '');
    attachLookup(field(squad, 'opponent'));
    attachLookup(field(squad, 'counter'));
    field(squad, 'opponent').on('lookup:chosen', function () {
        showLeader(squad, 'opponent');
        loadCounters(squad);
    });
    field(squad, 'counter').on('lookup:chosen', function () {
        showLeader(squad, 'counter');
    });
});
</script>
</body>
</html>
