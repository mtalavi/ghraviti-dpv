<?php
require_once __DIR__ . '/../includes/init.php';
require_login();
if (!is_super_admin()) {
  http_response_code(403);
  exit('Forbidden');
}

$counts = function (string $sql, array $params = []): int {
  $row = fetch_one($sql, $params);
  return (int) ($row['c'] ?? 0);
};

// Main Stats
$stats = [
  'users' => $counts('SELECT COUNT(*) AS c FROM users'),
  'admins' => $counts("SELECT COUNT(*) AS c FROM users WHERE role='admin'"),
  'supers' => $counts("SELECT COUNT(*) AS c FROM users WHERE role='super_admin'"),
  'events' => $counts('SELECT COUNT(*) AS c FROM events'),
  'active_events' => $counts('SELECT COUNT(*) AS c FROM events WHERE start_datetime >= NOW()'),
  'registrations' => $counts('SELECT COUNT(*) AS c FROM event_registrations'),
  'checked_in' => $counts("SELECT COUNT(*) AS c FROM event_registrations WHERE status='checked_in'"),
  'checked_out' => $counts("SELECT COUNT(*) AS c FROM event_registrations WHERE status='checked_out'"),
  'not_attended' => $counts("SELECT COUNT(*) AS c FROM event_registrations WHERE status='registered'"),
  'reg_today' => $counts("SELECT COUNT(*) AS c FROM event_registrations WHERE DATE(created_at)=CURDATE()"),
  'checkin_today' => $counts("SELECT COUNT(*) AS c FROM event_registrations WHERE DATE(checkin_time)=CURDATE() AND checkin_time IS NOT NULL"),
  'users_today' => $counts("SELECT COUNT(*) AS c FROM users WHERE DATE(created_at)=CURDATE()"),
];

// Emirate distribution
$emirateStats = fetch_all("
    SELECT emirate, COUNT(*) as count 
    FROM users 
    WHERE emirate IS NOT NULL AND emirate != '' 
    GROUP BY emirate 
    ORDER BY count DESC 
    LIMIT 7
");

// Top events by registrations
$topEvents = fetch_all("
    SELECT e.name, COUNT(r.id) as reg_count 
    FROM events e 
    LEFT JOIN event_registrations r ON e.id = r.event_id 
    GROUP BY e.id 
    ORDER BY reg_count DESC 
    LIMIT 5
");

$upcomingEvents = fetch_all(
  "SELECT id, name, start_datetime, location, public_slug FROM events WHERE start_datetime >= NOW() ORDER BY start_datetime ASC LIMIT 3"
);
$recentLogs = fetch_users_decrypted(
  "SELECT l.*, u.full_name FROM activity_logs l LEFT JOIN users u ON l.actor_user_id=u.id ORDER BY l.created_at DESC LIMIT 5"
);
$latestUsers = fetch_users_decrypted(
  "SELECT id, dp_code, full_name, role, created_at FROM users ORDER BY created_at DESC LIMIT 5"
);

render_header('Super Admin Dashboard');
?>
<style>
  .stat-card {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
  }

  .stat-number {
    font-size: 2.5rem;
    font-weight: 900;
    line-height: 1;
    color: #059669;
  }

  .stat-number-lg {
    font-size: 3rem;
  }

  .section-card {
    background: white;
    border-radius: 20px;
    padding: 1.5rem;
    border: 1px solid #e2e8f0;
    box-shadow: 0 4px 6px -1px rgb(0 0 0 / 0.05);
  }

  .progress-bar {
    height: 8px;
    border-radius: 4px;
    background: #e2e8f0;
    overflow: hidden;
  }

  .progress-fill {
    height: 100%;
    border-radius: 4px;
  }

  .quick-action {
    background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
    border: 1px solid #a7f3d0;
    border-radius: 16px;
    padding: 1rem 1.25rem;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    text-decoration: none;
  }

  .quick-action:hover {
    background: linear-gradient(135deg, #dcfce7 0%, #d1fae5 100%);
  }
</style>

<div class="dpv-container dpv-space-y-6">
  <!-- Page Header -->
  <div class="dpv-page-header">
    <div class="dpv-page-header__layout">
      <div>
        <p class="dpv-page-header__eyebrow">👑 Super Admin</p>
        <h1 class="dpv-page-header__title">Control Center</h1>
        <p class="dpv-page-header__subtitle">Real-time overview of your volunteer platform.</p>
      </div>
      <div class="dpv-page-header__actions flex flex-col items-end gap-1">
        <div id="dubaiClock" class="text-lg font-mono font-bold text-white">--:--:--</div>
        <div id="dubaiDate" class="text-sm text-white/90">Loading...</div>
      </div>
    </div>
  </div>
  <script>
    (function () {
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'];
      const dayNames = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

      function updateDubaiTime() {
        const now = new Date();
        // Dubai is UTC+4
        const dubaiOffset = 4 * 60; // minutes
        const localOffset = now.getTimezoneOffset(); // negative for east of UTC
        const dubaiTime = new Date(now.getTime() + (localOffset + dubaiOffset) * 60000);

        const hours = String(dubaiTime.getHours()).padStart(2, '0');
        const mins = String(dubaiTime.getMinutes()).padStart(2, '0');
        const secs = String(dubaiTime.getSeconds()).padStart(2, '0');

        const dayName = dayNames[dubaiTime.getDay()];
        const day = dubaiTime.getDate();
        const month = dubaiTime.getMonth() + 1;
        const monthName = monthNames[dubaiTime.getMonth()];
        const year = dubaiTime.getFullYear();

        document.getElementById('dubaiClock').textContent = `🕐 ${hours}:${mins}:${secs}`;
        document.getElementById('dubaiDate').textContent = `${dayName}, ${day}/${month} (${monthName}) ${year} - Dubai`;
      }

      updateDubaiTime();
      setInterval(updateDubaiTime, 1000);
    })();
  </script>

  <!-- Main Stats Grid -->
  <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
    <div class="stat-card">
      <p class="text-xs uppercase text-slate-500 font-semibold mb-1">Total Users</p>
      <div class="stat-number stat-number-lg"><?= $stats['users'] ?></div>
      <div class="mt-2 text-xs"><span
          class="px-2 py-1 bg-emerald-50 text-emerald-700 rounded-full font-semibold">+<?= $stats['users_today'] ?>
          today</span></div>
    </div>
    <div class="stat-card">
      <p class="text-xs uppercase text-slate-500 font-semibold mb-1">Events</p>
      <div class="stat-number stat-number-lg"><?= $stats['events'] ?></div>
      <div class="mt-2 text-xs text-slate-500"><?= $stats['active_events'] ?> upcoming</div>
    </div>
    <div class="stat-card">
      <p class="text-xs uppercase text-slate-500 font-semibold mb-1">Registrations</p>
      <div class="stat-number stat-number-lg"><?= $stats['registrations'] ?></div>
      <div class="mt-2 text-xs"><span
          class="px-2 py-1 bg-blue-50 text-blue-700 rounded-full font-semibold">+<?= $stats['reg_today'] ?> today</span>
      </div>
    </div>
    <div class="stat-card">
      <p class="text-xs uppercase text-slate-500 font-semibold mb-1">Check-ins Today</p>
      <div class="stat-number stat-number-lg"><?= $stats['checkin_today'] ?></div>
      <div class="mt-2 text-xs text-slate-500">Real-time</div>
    </div>
  </div>

  <!-- Attendance Breakdown -->
  <div class="section-card">
    <h2 class="text-lg font-bold text-slate-900 mb-4">Attendance Breakdown</h2>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
      <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-100">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-emerald-800">Checked In</span>
          <span class="text-2xl font-black text-emerald-700"><?= $stats['checked_in'] ?></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill bg-emerald-500"
            style="width: <?= $stats['registrations'] > 0 ? round($stats['checked_in'] / $stats['registrations'] * 100) : 0 ?>%;">
          </div>
        </div>
        <div class="text-xs text-emerald-600 mt-1">
          <?= $stats['registrations'] > 0 ? round($stats['checked_in'] / $stats['registrations'] * 100) : 0 ?>%
        </div>
      </div>
      <div class="p-4 rounded-xl bg-purple-50 border border-purple-100">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-purple-800">Checked Out</span>
          <span class="text-2xl font-black text-purple-700"><?= $stats['checked_out'] ?></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill bg-purple-500"
            style="width: <?= $stats['registrations'] > 0 ? round($stats['checked_out'] / $stats['registrations'] * 100) : 0 ?>%;">
          </div>
        </div>
        <div class="text-xs text-purple-600 mt-1">
          <?= $stats['registrations'] > 0 ? round($stats['checked_out'] / $stats['registrations'] * 100) : 0 ?>%
        </div>
      </div>
      <div class="p-4 rounded-xl bg-amber-50 border border-amber-100">
        <div class="flex items-center justify-between mb-2">
          <span class="text-sm font-semibold text-amber-800">Not Attended</span>
          <span class="text-2xl font-black text-amber-700"><?= $stats['not_attended'] ?></span>
        </div>
        <div class="progress-bar">
          <div class="progress-fill bg-amber-500"
            style="width: <?= $stats['registrations'] > 0 ? round($stats['not_attended'] / $stats['registrations'] * 100) : 0 ?>%;">
          </div>
        </div>
        <div class="text-xs text-amber-600 mt-1">
          <?= $stats['registrations'] > 0 ? round($stats['not_attended'] / $stats['registrations'] * 100) : 0 ?>%
        </div>
      </div>
    </div>
  </div>

  <!-- Quick Actions & Emirate Stats -->
  <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Quick Actions -->
    <div class="section-card">
      <h2 class="text-lg font-bold text-slate-900 mb-4">Quick Actions</h2>
      <div class="grid grid-cols-2 gap-3">
        <a href="<?= BASE_URL ?>/admin/users.php" class="quick-action">
          <span class="text-2xl">👥</span>
          <div>
            <div class="font-semibold text-slate-900">All Users</div>
            <div class="text-xs text-slate-500"><?= $stats['users'] ?> registered</div>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/admin/admins.php" class="quick-action">
          <span class="text-2xl">🛡️</span>
          <div>
            <div class="font-semibold text-slate-900">Admins</div>
            <div class="text-xs text-slate-500"><?= $stats['admins'] ?> admins</div>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/admin/events.php" class="quick-action">
          <span class="text-2xl">📅</span>
          <div>
            <div class="font-semibold text-slate-900">Events</div>
            <div class="text-xs text-slate-500"><?= $stats['events'] ?> total</div>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/admin/custom_export.php" class="quick-action">
          <span class="text-2xl">📊</span>
          <div>
            <div class="font-semibold text-slate-900">Custom CSV</div>
            <div class="text-xs text-slate-500">Export data</div>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/admin/logs.php" class="quick-action">
          <span class="text-2xl">📋</span>
          <div>
            <div class="font-semibold text-slate-900">Activity Logs</div>
            <div class="text-xs text-slate-500">Audit trail</div>
          </div>
        </a>
        <a href="<?= BASE_URL ?>/admin/settings_role.php" class="quick-action">
          <span class="text-2xl">⚙️</span>
          <div>
            <div class="font-semibold text-slate-900">Settings</div>
            <div class="text-xs text-slate-500">Role title</div>
          </div>
        </a>
      </div>
    </div>

    <!-- Emirate Distribution -->
    <div class="section-card">
      <h2 class="text-lg font-bold text-slate-900 mb-4">Users by Emirate</h2>
      <?php if (!$emirateStats): ?>
        <p class="text-sm text-slate-500">No data.</p>
      <?php else: ?>
        <?php $maxEm = max(array_column($emirateStats, 'count')) ?: 1; ?>
        <div class="space-y-3">
          <?php foreach ($emirateStats as $em): ?>
            <div class="flex items-center gap-3">
              <div class="w-24 text-sm font-medium text-slate-700 truncate"><?= h($em['emirate']) ?></div>
              <div class="flex-1 progress-bar">
                <div class="progress-fill bg-gradient-to-r from-emerald-400 to-emerald-600"
                  style="width: <?= round($em['count'] / $maxEm * 100) ?>%;"></div>
              </div>
              <div class="text-sm font-bold text-slate-900 w-10 text-right"><?= $em['count'] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Top Events -->
  <div class="section-card">
    <h2 class="text-lg font-bold text-slate-900 mb-4">Top Events by Registrations</h2>
    <?php if (!$topEvents): ?>
      <p class="text-sm text-slate-500">No events yet.</p>
    <?php else: ?>
      <?php $maxReg = max(array_column($topEvents, 'reg_count')) ?: 1; ?>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-4">
        <?php foreach ($topEvents as $i => $ev): ?>
          <div class="p-4 rounded-xl bg-slate-50 border border-slate-100 text-center">
            <div class="text-3xl font-black text-emerald-600"><?= $ev['reg_count'] ?></div>
            <div class="text-sm font-semibold text-slate-900 mt-2 truncate"><?= h($ev['name']) ?></div>
            <div class="text-xs text-slate-500">#<?= $i + 1 ?> Event</div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Bottom Row -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- Upcoming Events -->
    <div class="section-card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900">Upcoming Events</h2>
        <a href="<?= BASE_URL ?>/admin/events.php" class="text-sm text-emerald-700 font-semibold">View all</a>
      </div>
      <?php if (!$upcomingEvents): ?>
        <p class="text-sm text-slate-500">No upcoming events.</p>
      <?php else: ?>
        <div class="space-y-3">
          <?php foreach ($upcomingEvents as $ev): ?>
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-100">
              <div class="font-semibold text-slate-900"><?= h($ev['name']) ?></div>
              <div class="text-xs text-slate-600 mt-1"><?= format_dubai_datetime($ev['start_datetime'], 'full') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Recent Logs -->
    <div class="section-card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900">Latest Activity</h2>
        <a href="<?= BASE_URL ?>/admin/logs.php" class="text-sm text-emerald-700 font-semibold">View all</a>
      </div>
      <?php if (!$recentLogs): ?>
        <p class="text-sm text-slate-500">No activity yet.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($recentLogs as $log): ?>
            <div class="p-2 rounded-xl bg-slate-50 border border-slate-100">
              <div class="flex items-center justify-between text-xs text-slate-500">
                <span class="font-semibold text-slate-700"><?= h($log['action']) ?></span>
                <span><?= date('H:i', strtotime($log['created_at'])) ?></span>
              </div>
              <div class="text-xs text-slate-600"><?= h($log['full_name'] ?: 'System') ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Newest Users -->
    <div class="section-card">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-slate-900">Newest Users</h2>
        <a href="<?= BASE_URL ?>/admin/users.php" class="text-sm text-emerald-700 font-semibold">View all</a>
      </div>
      <?php if (!$latestUsers): ?>
        <p class="text-sm text-slate-500">No users yet.</p>
      <?php else: ?>
        <div class="space-y-2">
          <?php foreach ($latestUsers as $user): ?>
            <div class="p-2 rounded-xl bg-slate-50 border border-slate-100 flex items-center justify-between">
              <div>
                <div class="text-sm font-semibold text-slate-900"><?= h($user['full_name']) ?></div>
                <div class="text-xs text-slate-600"><?= h($user['dp_code']) ?></div>
              </div>
              <a href="<?= BASE_URL ?>/admin/user_edit.php?id=<?= $user['id'] ?>"
                class="text-xs text-emerald-700 font-semibold">Edit</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php render_footer(); ?>