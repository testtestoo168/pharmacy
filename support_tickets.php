<?php
require_once 'includes/config.php';
$pageTitle = t('support.title');
require_once 'includes/header.php';
$tid = getTenantId();
$bid = getBranchId();

// إنشاء تذكرة
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_ticket'])) {
    try {
        verifyCsrfToken();
        $ticketNum = 'TK-' . str_pad($pdo->query("SELECT COALESCE(MAX(CAST(REPLACE(ticket_number,'TK-','') AS UNSIGNED)),0)+1 FROM support_tickets WHERE tenant_id = $tid")->fetchColumn(), 6, '0', STR_PAD_LEFT);
        $pdo->prepare("INSERT INTO support_tickets (tenant_id, user_id, ticket_number, subject, description, category, priority, status) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$tid, $_SESSION['user_id'], $ticketNum, $_POST['subject'], $_POST['description'], $_POST['category'] ?? 'question', $_POST['priority'] ?? 'normal', 'open']);
        logActivity($pdo, 'g.new_ticket', $ticketNum, 'support');
        echo '<div class="alert alert-success"><i class="fas fa-check-circle"></i> ' . t('saved_success') . ' — ' . $ticketNum . '</div>';
    } catch (Exception $e) {
        echo '<div class="alert alert-danger">' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// إضافة رد
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_reply'])) {
    try {
        verifyCsrfToken();
        $ticketId = intval($_POST['ticket_id']);
        // تحقق إن التذكرة تتبع هذا الـ tenant
        $check = $pdo->prepare("SELECT id FROM support_tickets WHERE id = ? AND tenant_id = ?");
        $check->execute([$ticketId, $tid]);
        if ($check->fetch()) {
            $pdo->prepare("INSERT INTO support_ticket_replies (ticket_id, user_id, is_admin_reply, message) VALUES (?,?,0,?)")
                ->execute([$ticketId, $_SESSION['user_id'], $_POST['reply_message']]);
            $pdo->prepare("UPDATE support_tickets SET updated_at = NOW() WHERE id = ? AND tenant_id = ?")->execute([$ticketId, $tid]);
        }
        header("Location: support_tickets?view=$ticketId&msg=replied"); exit;
    } catch (Exception $e) {}
}

$viewTicket = intval($_GET['view'] ?? 0);
$statusFilter = $_GET['status'] ?? '';

$catLabels = ['bug'=>'خلل','feature'=>'طلب ميزة','question'=>'استفسار','billing'=>'فوترة','other'=>t('other')];
$priorityLabels = ['low'=>[t('support.low'),'#64748b'],'normal'=>[t('support.normal'),'#3b82f6'],'high'=>[t('support.high'),'#f59e0b'],'urgent'=>[t('support.urgent'),'#ef4444']];
$statusLabelsMap = ['open'=>[t('g.open'),'#22c55e'],'in_progress'=>[t('g.in_progress'),'#3b82f6'],'waiting'=>[t('support.waiting'),'#f59e0b'],'resolved'=>[t('g.resolved'),'#8b5cf6'],'closed'=>[t('g.closed'),'#64748b']];
?>

<?php if (isset($_GET['msg'])): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?= t('saved_success') ?></div><?php endif; ?>

<?php if ($viewTicket): ?>
<!-- عرض تفاصيل التذكرة -->
<?php
$ticket = $pdo->prepare("SELECT st.*, u.full_name as user_name FROM support_tickets st LEFT JOIN users u ON u.id = st.user_id WHERE st.id = ? AND st.tenant_id = ?");
$ticket->execute([$viewTicket, $tid]);
$ticket = $ticket->fetch();
if (!$ticket) { echo '<div class="alert alert-danger">' . t('g.not_found') . '</div>'; require_once 'includes/footer.php'; exit; }

$replies = $pdo->prepare("SELECT r.*, u.full_name FROM support_ticket_replies r LEFT JOIN users u ON u.id = r.user_id WHERE r.ticket_id = ? ORDER BY r.created_at ASC");
$replies->execute([$viewTicket]);
$replyList = $replies->fetchAll();
?>
<div class="card">
    <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
        <div>
            <h3><i class="fas fa-ticket-alt"></i> <?= htmlspecialchars($ticket['ticket_number']) ?> — <?= htmlspecialchars($ticket['subject']) ?></h3>
            <div style="font-size:12px;color:#64748b;margin-top:4px;">
                بواسطة <?= htmlspecialchars($ticket['user_name']) ?> — <?= $ticket['created_at'] ?>
                | <span style="color:<?= $priorityLabels[$ticket['priority']][1] ?? '#666' ?>;font-weight:700;"><?= $priorityLabels[$ticket['priority']][0] ?? '' ?></span>
                | <?= $catLabels[$ticket['category']] ?? '' ?>
            </div>
        </div>
        <div>
            <?php $sl = $statusLabelsMap[$ticket['status']] ?? ['','#666']; ?>
            <span style="background:<?= $sl[1] ?>22;color:<?= $sl[1] ?>;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;"><?= $sl[0] ?></span>
            <a href="support_tickets" class="btn btn-sm" style="background:var(--secondary);color:var(--foreground);margin-right:8px;padding:6px 12px;text-decoration:none;"><i class="fas fa-arrow-right"></i><?= t('back') ?></a>
        </div>
    </div>
    <div class="card-body">
        <!-- الوصف -->
        <div style="background:var(--secondary);border:1px solid var(--border);border-radius:8px;padding:16px;margin-bottom:20px;">
            <p style="white-space:pre-wrap;line-height:1.8;"><?= nl2br(htmlspecialchars($ticket['description'])) ?></p>
        </div>
        
        <!-- الردود -->
        <?php foreach ($replyList as $r): ?>
        <div style="background:<?= $r['is_admin_reply'] ? '#eff6ff' : 'var(--secondary)' ?>;border:1px solid <?= $r['is_admin_reply'] ? '#bfdbfe' : 'var(--border)' ?>;border-radius:8px;padding:14px;margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:8px;">
                <span style="font-weight:700;font-size:13px;"><?= $r['is_admin_reply'] ? '<i class="fas fa-headset" style="color:#3b82f6;"></i> ' . t('nav.support') : '<i class="fas fa-user"></i> ' . htmlspecialchars($r['full_name'] ?? t('g.user')) ?></span>
                <span style="font-size:11px;color:#64748b;"><?= $r['created_at'] ?></span>
            </div>
            <p style="white-space:pre-wrap;font-size:14px;"><?= nl2br(htmlspecialchars($r['message'])) ?></p>
        </div>
        <?php endforeach; ?>
        
        <!-- إضافة رد -->
        <?php if (in_array($ticket['status'], ['open', 'in_progress', 'waiting'])): ?>
        <form method="POST" style="margin-top:16px;">
            <?= csrfField() ?>
            <input type="hidden" name="add_reply" value="1">
            <input type="hidden" name="ticket_id" value="<?= $viewTicket ?>">
            <textarea name="reply_message" class="form-control" rows="3" required placeholder="<?= t('support.write_reply') ?>" style="margin-bottom:8px;"></textarea>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-paper-plane"></i><?= t('g.send_reply') ?></button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php else: ?>
<!-- قائمة التذاكر -->
<div class="card no-print">
    <div class="card-body" style="padding:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
        <div style="display:flex;gap:6px;">
            <a href="support_tickets" class="btn btn-sm <?= !$statusFilter ? 'btn-primary' : '' ?>" style="padding:6px 12px;text-decoration:none;"><?= t('all') ?></a>
            <a href="support_tickets?status=open" class="btn btn-sm <?= $statusFilter==='open' ? 'btn-primary' : '' ?>" style="padding:6px 12px;text-decoration:none;"><?= t('g.open') ?></a>
            <a href="support_tickets?status=in_progress" class="btn btn-sm <?= $statusFilter==='in_progress' ? 'btn-primary' : '' ?>" style="padding:6px 12px;text-decoration:none;"><?= t('g.in_progress') ?></a>
            <a href="support_tickets?status=resolved" class="btn btn-sm <?= $statusFilter==='resolved' ? 'btn-primary' : '' ?>" style="padding:6px 12px;text-decoration:none;"><?= t('g.resolved') ?></a>
        </div>
        <button onclick="document.getElementById('newTicketModal').style.display='flex'" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i><?= t('g.new_ticket') ?></button>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table>
            <thead><tr><th><?= t('g.number') ?></th><th><?= t('g.subject') ?></th><th><?= t('products.category') ?></th><th><?= t('g.priority') ?></th><th><?= t('status') ?></th><th><?= t('date') ?></th><th><?= t('g.last_update') ?></th></tr></thead>
            <tbody>
            <?php
            $where = "WHERE tenant_id = ?";
            $params = [$tid];
            if ($statusFilter) { $where .= " AND status = ?"; $params[] = $statusFilter; }
            $tks = $pdo->prepare("SELECT * FROM support_tickets $where ORDER BY CASE WHEN status IN('open','in_progress') THEN 0 ELSE 1 END, updated_at DESC LIMIT 50");
            $tks->execute($params);
            $tickets = $tks->fetchAll();
            if (empty($tickets)): ?>
            <tr><td colspan="7" style="text-align:center;padding:40px;color:#94a3b8;"><?= t('no_results') ?></td></tr>
            <?php else: foreach ($tickets as $t): ?>
            <tr style="cursor:pointer;" onclick="location.href='support_tickets?view=<?= $t['id'] ?>'">
                <td style="font-weight:700;"><?= htmlspecialchars($t['ticket_number']) ?></td>
                <td><?= htmlspecialchars($t['subject']) ?></td>
                <td style="font-size:12px;"><?= $catLabels[$t['category']] ?? $t['category'] ?></td>
                <td><span style="color:<?= $priorityLabels[$t['priority']][1] ?? '#666' ?>;font-weight:700;font-size:12px;"><?= $priorityLabels[$t['priority']][0] ?? '' ?></span></td>
                <td><?php $sl = $statusLabelsMap[$t['status']] ?? ['','#666']; ?><span style="background:<?= $sl[1] ?>22;color:<?= $sl[1] ?>;padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;"><?= $sl[0] ?></span></td>
                <td style="font-size:12px;color:#64748b;"><?= date('Y-m-d', strtotime($t['created_at'])) ?></td>
                <td style="font-size:12px;color:#64748b;"><?= date('Y-m-d H:i', strtotime($t['updated_at'])) ?></td>
            </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: تذكرة جديدة -->
<div id="newTicketModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:1000;align-items:center;justify-content:center;padding:20px;">
    <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;width:100%;max-width:550px;">
        <div style="padding:16px 20px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;">
            <h3 style="font-size:16px;"><i class="fas fa-headset"></i> <?= t('g.new_ticket') ?></h3>
            <button onclick="document.getElementById('newTicketModal').style.display='none'" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--foreground);">&times;</button>
        </div>
        <div style="padding:20px;">
            <form method="POST">
                <?= csrfField() ?>
                <input type="hidden" name="create_ticket" value="1">
                <div style="margin-bottom:12px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('g.subject') ?> *</label><input type="text" name="subject" class="form-control" required></div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
                    <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('products.category') ?></label>
                        <select name="category" class="form-control">
                            <?php foreach ($catLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('g.priority') ?></label>
                        <select name="priority" class="form-control">
                            <?php foreach ($priorityLabels as $k => $v): ?><option value="<?= $k ?>"><?= $v[0] ?></option><?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom:16px;"><label style="display:block;font-size:13px;font-weight:600;margin-bottom:4px;"><?= t('description') ?> *</label><textarea name="description" class="form-control" rows="4" required placeholder="اشرح المشكلة أو الطلب بالتفصيل..."></textarea></div>
                <div style="text-align:left;">
                    <button type="button" onclick="document.getElementById('newTicketModal').style.display='none'" class="btn btn-sm" style="background:var(--secondary);color:var(--foreground);padding:8px 16px;"><?= t('cancel') ?></button>
                    <button type="submit" class="btn btn-primary btn-sm" style="padding:8px 20px;"><i class="fas fa-paper-plane"></i><?= t('g.send') ?></button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
