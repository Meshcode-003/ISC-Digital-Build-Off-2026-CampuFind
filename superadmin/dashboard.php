<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/csrf.php';

requireRole(['super_admin']);

$currentUserId = (int) $_SESSION['user_id'];
$moduleLabels = [
    'lost_items' => 'Lost Items',
    'notices' => 'Notices',
    'mentorship' => 'Mentorship',
];
$roleNames = ['student', 'staff', 'admin', 'super_admin'];
$accountStatuses = ['active', 'suspended', 'inactive'];
$escape = static fn ($value): string => htmlspecialchars(
    (string) ($value ?? ''),
    ENT_QUOTES | ENT_SUBSTITUTE,
    'UTF-8'
);
$logActivity = static function (string $action, string $description) use ($pdo, $currentUserId): void {
    $statement = $pdo->prepare(
        'INSERT INTO activity (user_id, action, description, ip_address, user_agent)
         VALUES (:user_id, :action, :description, :ip_address, :user_agent)'
    );
    $statement->execute([
        'user_id' => $currentUserId,
        'action' => $action,
        'description' => $description,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
        'user_agent' => isset($_SERVER['HTTP_USER_AGENT'])
            ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500)
            : null,
    ]);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrfToken();

    $actionInput = $_POST['action'] ?? '';
    $action = is_string($actionInput) ? $actionInput : '';
    $targetId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);

    try {
        if (in_array($action, ['user_status', 'user_role'], true)) {
            if (!$targetId || $targetId < 1 || $targetId === $currentUserId) {
                throw new RuntimeException('You cannot modify your own account or an invalid user.');
            }

            $pdo->beginTransaction();
            $actorStatement = $pdo->prepare(
                'SELECT r.role_name, u.account_status
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $actorStatement->execute(['user_id' => $currentUserId]);
            $actor = $actorStatement->fetch();
            if (!$actor || $actor['role_name'] !== 'super_admin' || $actor['account_status'] !== 'active') {
                throw new RuntimeException('An active Super Admin account is required.');
            }

            $targetStatement = $pdo->prepare(
                'SELECT u.id, u.first_name, u.last_name, u.username,
                        u.account_status, r.role_name
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $targetStatement->execute(['user_id' => $targetId]);
            $target = $targetStatement->fetch();
            if (!$target || $target['role_name'] === 'super_admin') {
                throw new RuntimeException('The selected account cannot be modified.');
            }

            $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
            if ($targetName === '') {
                $targetName = $target['username'];
            }

            if ($action === 'user_status') {
                $newStatusInput = $_POST['account_status'] ?? '';
                $newStatus = is_string($newStatusInput) ? $newStatusInput : '';
                if (!in_array($newStatus, $accountStatuses, true)) {
                    throw new RuntimeException('Select a valid account status.');
                }
                if ($newStatus === $target['account_status']) {
                    throw new RuntimeException('The account already has that status.');
                }

                $update = $pdo->prepare(
                    'UPDATE users
                     SET account_status = :account_status
                     WHERE id = :user_id'
                );
                $update->execute([
                    'account_status' => $newStatus,
                    'user_id' => $targetId,
                ]);
                $logActivity(
                    'ACCOUNT_STATUS_CHANGED',
                    sprintf('Changed account status for %s (user ID %d) from %s to %s.', $targetName, $targetId, $target['account_status'], $newStatus)
                );
            } else {
                $newRoleInput = $_POST['role_name'] ?? '';
                $newRole = is_string($newRoleInput) ? $newRoleInput : '';
                if (!in_array($newRole, ['student', 'staff'], true)) {
                    throw new RuntimeException('Role changes may only assign the student or staff role. Use administrator registration to create administrators.');
                }
                if ($newRole === $target['role_name']) {
                    throw new RuntimeException('The account already has that role.');
                }

                $roleStatement = $pdo->prepare(
                    'SELECT id FROM roles WHERE role_name = :role_name LIMIT 1'
                );
                $roleStatement->execute(['role_name' => $newRole]);
                $newRoleId = $roleStatement->fetchColumn();
                if ($newRoleId === false) {
                    throw new RuntimeException('The selected role is not configured.');
                }

                $update = $pdo->prepare(
                    'UPDATE users SET role_id = :role_id WHERE id = :user_id'
                );
                $update->execute([
                    'role_id' => $newRoleId,
                    'user_id' => $targetId,
                ]);
                if ($target['role_name'] === 'admin') {
                    $deleteAssignments = $pdo->prepare(
                        'DELETE FROM module_admin_assignments WHERE user_id = :user_id'
                    );
                    $deleteAssignments->execute(['user_id' => $targetId]);
                }
                $logActivity(
                    'USER_ROLE_CHANGED',
                    sprintf('Changed role for %s (user ID %d) from %s to %s.', $targetName, $targetId, $target['role_name'], $newRole)
                );
            }

            $pdo->commit();
            $_SESSION['superadmin_flash'] = ['kind' => 'success', 'message' => 'Account changes saved.'];
        } elseif ($action === 'report_decision') {
            $reportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT);
            $decisionInput = $_POST['decision'] ?? '';
            $decision = is_string($decisionInput) ? $decisionInput : '';
            $reasonInput = $_POST['rejection_reason'] ?? '';
            $reason = is_string($reasonInput) ? trim($reasonInput) : '';
            if (!$reportId || $reportId < 1 || !in_array($decision, ['approved', 'rejected'], true)) {
                throw new RuntimeException('Select a valid report and review decision.');
            }
            if ($decision === 'rejected' && ($reason === '' || mb_strlen($reason) > 5000)) {
                throw new RuntimeException('Provide a rejection reason of up to 5,000 characters.');
            }

            $pdo->beginTransaction();
            $actorStatement = $pdo->prepare(
                'SELECT r.role_name, u.account_status
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $actorStatement->execute(['user_id' => $currentUserId]);
            $actor = $actorStatement->fetch();
            if (!$actor || $actor['role_name'] !== 'super_admin' || $actor['account_status'] !== 'active') {
                throw new RuntimeException('An active Super Admin account is required.');
            }

            $reportStatement = $pdo->prepare(
                'SELECT id, item_id, reporter_id, status
                 FROM lost_item_reports
                 WHERE id = :report_id
                 FOR UPDATE'
            );
            $reportStatement->execute(['report_id' => $reportId]);
            $report = $reportStatement->fetch();
            if (!$report || $report['status'] !== 'pending') {
                throw new RuntimeException('This report has already been reviewed or no longer exists.');
            }

            $updateReport = $pdo->prepare(
                'UPDATE lost_item_reports
                 SET status = :status,
                     reviewed_by = :reviewed_by,
                     reviewed_at = CURRENT_TIMESTAMP,
                     rejection_reason = :rejection_reason
                 WHERE id = :report_id
                   AND status = \'pending\''
            );
            $updateReport->execute([
                'status' => $decision,
                'reviewed_by' => $currentUserId,
                'rejection_reason' => $decision === 'rejected' ? $reason : null,
                'report_id' => $reportId,
            ]);
            if ($updateReport->rowCount() !== 1) {
                throw new RuntimeException('The report changed while being reviewed. Refresh and retry.');
            }

            $notification = $pdo->prepare(
                'INSERT INTO notification (
                    user_id, title, message, notification_type, related_item_id, related_report_id
                 ) VALUES (
                    :user_id, :title, :message, :notification_type, :item_id, :report_id
                 )'
            );
            $notification->execute([
                'user_id' => (int) $report['reporter_id'],
                'title' => $decision === 'approved' ? 'Lost item report approved' : 'Lost item report not approved',
                'message' => $decision === 'approved'
                    ? 'Your lost item report #' . $reportId . ' was approved.'
                    : 'Your lost item report #' . $reportId . ' was not approved. Reason: ' . $reason,
                'notification_type' => $decision === 'approved' ? 'approval' : 'rejection',
                'item_id' => (int) $report['item_id'],
                'report_id' => $reportId,
            ]);
            $logActivity(
                'LOST_REPORT_' . strtoupper($decision),
                sprintf('Marked lost item report %d as %s.', $reportId, $decision)
            );
            $pdo->commit();
            $_SESSION['superadmin_flash'] = ['kind' => 'success', 'message' => 'Lost item report review saved and reporter notified.'];
        } elseif ($action === 'admin_modules') {
            if (!$targetId || $targetId < 1 || $targetId === $currentUserId) {
                throw new RuntimeException('Select a valid administrator.');
            }
            $rawModules = $_POST['modules'] ?? [];
            if (!is_array($rawModules)) {
                throw new RuntimeException('Invalid module selection.');
            }
            $selectedModules = [];
            foreach ($rawModules as $rawModule) {
                if (!is_string($rawModule) || !array_key_exists($rawModule, $moduleLabels)) {
                    throw new RuntimeException('One or more selected modules are invalid.');
                }
                $selectedModules[$rawModule] = $rawModule;
            }
            $selectedModules = array_values($selectedModules);

            $pdo->beginTransaction();
            $actorStatement = $pdo->prepare(
                'SELECT r.role_name, u.account_status
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $actorStatement->execute(['user_id' => $currentUserId]);
            $actor = $actorStatement->fetch();
            if (!$actor || $actor['role_name'] !== 'super_admin' || $actor['account_status'] !== 'active') {
                throw new RuntimeException('An active Super Admin account is required.');
            }

            $targetStatement = $pdo->prepare(
                'SELECT u.id, u.first_name, u.last_name, u.username, u.account_status, r.role_name
                 FROM users u
                 INNER JOIN roles r ON r.id = u.role_id
                 WHERE u.id = :user_id
                 FOR UPDATE'
            );
            $targetStatement->execute(['user_id' => $targetId]);
            $target = $targetStatement->fetch();
            if (
                !$target
                || $target['role_name'] !== 'admin'
                || ($target['account_status'] !== 'active' && $selectedModules !== [])
            ) {
                throw new RuntimeException('Only active administrator accounts can receive module assignments. Inactive administrator assignments may only be removed.');
            }

            $deleteAssignments = $pdo->prepare(
                'DELETE FROM module_admin_assignments WHERE user_id = :user_id'
            );
            $deleteAssignments->execute(['user_id' => $targetId]);

            $insertAssignment = $pdo->prepare(
                'INSERT INTO module_admin_assignments (user_id, module_key, assigned_by)
                 VALUES (:user_id, :module_key, :assigned_by)'
            );
            foreach ($selectedModules as $module) {
                $insertAssignment->execute([
                    'user_id' => $targetId,
                    'module_key' => $module,
                    'assigned_by' => $currentUserId,
                ]);
            }

            $targetName = trim($target['first_name'] . ' ' . $target['last_name']);
            $targetName = $targetName !== '' ? $targetName : $target['username'];
            $assignmentNames = array_map(
                static fn (string $module): string => $moduleLabels[$module],
                $selectedModules
            );
            $logActivity(
                'ADMIN_MODULES_CHANGED',
                sprintf(
                    'Updated module assignments for %s (user ID %d): %s.',
                    $targetName,
                    $targetId,
                    $assignmentNames ? implode(', ', $assignmentNames) : 'no assigned modules'
                )
            );
            $pdo->commit();
            $_SESSION['superadmin_flash'] = ['kind' => 'success', 'message' => 'Administrator module assignments saved.'];
        } else {
            throw new RuntimeException('Unsupported dashboard action.');
        }
    } catch (RuntimeException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['superadmin_flash'] = ['kind' => 'error', 'message' => $exception->getMessage()];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Super Admin dashboard action failed: ' . $exception->getMessage());
        $_SESSION['superadmin_flash'] = [
            'kind' => 'error',
            'message' => 'The requested change could not be completed. Please try again.',
        ];
    }

    $returnSection = $_POST['return_section'] ?? 'overview';
    $returnSection = is_string($returnSection)
        && in_array($returnSection, ['overview', 'users', 'lost-items', 'notices', 'mentorship', 'administrators', 'activity', 'notifications', 'settings'], true)
        ? $returnSection
        : 'overview';
    header('Location: dashboard.php#' . $returnSection);
    exit;
}

$flash = $_SESSION['superadmin_flash'] ?? null;
unset($_SESSION['superadmin_flash']);
$csrfToken = generateCsrfToken();

$counts = [
    'users' => (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'active_users' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'active'")->fetchColumn(),
    'suspended_users' => (int) $pdo->query("SELECT COUNT(*) FROM users WHERE account_status = 'suspended'")->fetchColumn(),
    'admins' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'admin'")->fetchColumn(),
    'superadmins' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id WHERE r.role_name = 'super_admin'")->fetchColumn(),
    'items' => (int) $pdo->query('SELECT COUNT(*) FROM items')->fetchColumn(),
    'pending_items' => (int) $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'pending'")->fetchColumn(),
    'recovered_items' => (int) $pdo->query("SELECT COUNT(*) FROM items WHERE status = 'recovered'")->fetchColumn(),
    'reports' => (int) $pdo->query('SELECT COUNT(*) FROM lost_item_reports')->fetchColumn(),
    'pending_reports' => (int) $pdo->query("SELECT COUNT(*) FROM lost_item_reports WHERE status = 'pending'")->fetchColumn(),
    'claims' => (int) $pdo->query('SELECT COUNT(*) FROM claims')->fetchColumn(),
    'pending_claims' => (int) $pdo->query("SELECT COUNT(*) FROM claims WHERE status = 'pending'")->fetchColumn(),
    'notices' => (int) $pdo->query('SELECT COUNT(*) FROM notices')->fetchColumn(),
    'published_notices' => (int) $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'published' AND published_at <= CURRENT_TIMESTAMP AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)")->fetchColumn(),
    'draft_notices' => (int) $pdo->query("SELECT COUNT(*) FROM notices WHERE status = 'draft'")->fetchColumn(),
    'mentor_applications' => (int) $pdo->query('SELECT COUNT(*) FROM mentor_applications')->fetchColumn(),
    'pending_applications' => (int) $pdo->query("SELECT COUNT(*) FROM mentor_applications WHERE status = 'pending'")->fetchColumn(),
    'mentor_profiles' => (int) $pdo->query("SELECT COUNT(*) FROM mentor_profiles WHERE status = 'active'")->fetchColumn(),
    'unread_notifications' => (int) $pdo->query('SELECT COUNT(*) FROM notification WHERE is_read = 0')->fetchColumn(),
    'unassigned_admins' => (int) $pdo->query("SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id LEFT JOIN module_admin_assignments maa ON maa.user_id = u.id WHERE r.role_name = 'admin' AND u.account_status = 'active' AND maa.id IS NULL")->fetchColumn(),
];

$recentActivities = $pdo->query(
    'SELECT a.id, a.action, a.description, a.created_at,
            CONCAT(u.first_name, \' \', u.last_name) AS actor_name,
            u.username AS actor_username
     FROM activity a
     LEFT JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 8'
)->fetchAll();
$mentorProfiles = $pdo->query(
     'SELECT mp.user_id, mp.headline, mp.bio, mp.status, mp.accepting_requests,
             mp.created_at, u.first_name, u.last_name, u.username,
             GROUP_CONCAT(DISTINCT me.expertise_name ORDER BY me.expertise_name SEPARATOR \', \') AS expertise
      FROM mentor_profiles mp
      INNER JOIN users u ON u.id = mp.user_id
      LEFT JOIN mentor_expertise me ON me.mentor_user_id = mp.user_id
      GROUP BY mp.user_id, mp.headline, mp.bio, mp.status, mp.accepting_requests,
               mp.created_at, u.first_name, u.last_name, u.username
      ORDER BY mp.created_at DESC, mp.user_id DESC
      LIMIT 10'
)->fetchAll();
$mentorshipRequests = $pdo->query(
     'SELECT mr.id, mr.message, mr.mentor_response, mr.status, mr.created_at,
             CONCAT(student.first_name, \' \', student.last_name) AS student_name,
             student.username AS student_username,
             CONCAT(mentor.first_name, \' \', mentor.last_name) AS mentor_name,
             mentor.username AS mentor_username,
             mp.headline AS mentor_headline
      FROM mentorship_requests mr
      INNER JOIN users student ON student.id = mr.student_user_id
      INNER JOIN mentor_profiles mp ON mp.user_id = mr.mentor_user_id
      INNER JOIN users mentor ON mentor.id = mp.user_id
      ORDER BY mr.created_at DESC, mr.id DESC
      LIMIT 10'
)->fetchAll();

$recentUsers = $pdo->query(
    'SELECT u.id, u.first_name, u.last_name, u.username, u.email,
            u.account_status, u.created_at, r.role_name
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id
     ORDER BY u.created_at DESC, u.id DESC
     LIMIT 8'
)->fetchAll();

$userSearch = is_string($_GET['user_search'] ?? null) ? trim($_GET['user_search']) : '';
$userRoleFilter = is_string($_GET['user_role'] ?? null) ? $_GET['user_role'] : '';
$userStatusFilter = is_string($_GET['user_status'] ?? null) ? $_GET['user_status'] : '';
$userModuleFilter = is_string($_GET['user_module'] ?? null) ? $_GET['user_module'] : '';
$userConditions = [];
$userParams = [];
if ($userSearch !== '') {
    $userConditions[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE :user_name
        OR u.email LIKE :user_email
        OR u.username LIKE :user_username
        OR u.phone LIKE :user_phone)";
    $userTerm = '%' . $userSearch . '%';
    $userParams['user_name'] = $userTerm;
    $userParams['user_email'] = $userTerm;
    $userParams['user_username'] = $userTerm;
    $userParams['user_phone'] = $userTerm;
}
if (in_array($userRoleFilter, $roleNames, true)) {
    $userConditions[] = 'r.role_name = :user_role';
    $userParams['user_role'] = $userRoleFilter;
} else {
    $userRoleFilter = '';
}
if (in_array($userStatusFilter, $accountStatuses, true)) {
    $userConditions[] = 'u.account_status = :user_status';
    $userParams['user_status'] = $userStatusFilter;
} else {
    $userStatusFilter = '';
}
if (array_key_exists($userModuleFilter, $moduleLabels)) {
    $userConditions[] = 'EXISTS (
        SELECT 1 FROM module_admin_assignments filter_assignment
        WHERE filter_assignment.user_id = u.id
          AND filter_assignment.module_key = :user_module
    )';
    $userParams['user_module'] = $userModuleFilter;
} else {
    $userModuleFilter = '';
}
$usersSql = 'SELECT u.id, u.first_name, u.last_name, u.username, u.email,
                    u.phone, u.school_id, u.department_id, u.account_status,
                    u.last_login_at, u.created_at, r.role_name,
                    s.school_name, d.department_name,
                    GROUP_CONCAT(DISTINCT maa.module_key ORDER BY maa.module_key SEPARATOR \',\') AS modules
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             LEFT JOIN schools s ON s.id = u.school_id
             LEFT JOIN departments d ON d.id = u.department_id
             LEFT JOIN module_admin_assignments maa ON maa.user_id = u.id';
if ($userConditions) {
    $usersSql .= ' WHERE ' . implode(' AND ', $userConditions);
}
$usersSql .= ' GROUP BY u.id, u.first_name, u.last_name, u.username, u.email,
                       u.phone, u.school_id, u.department_id, u.account_status,
                       u.last_login_at, u.created_at, r.role_name,
                       s.school_name, d.department_name
               ORDER BY u.created_at DESC, u.id DESC
               LIMIT 100';
$usersStatement = $pdo->prepare($usersSql);
$usersStatement->execute($userParams);
$users = $usersStatement->fetchAll();

$admins = $pdo->query(
    "SELECT u.id, u.first_name, u.last_name, u.username, u.email, u.account_status,
            GROUP_CONCAT(DISTINCT maa.module_key ORDER BY maa.module_key SEPARATOR ',') AS modules
     FROM users u
     INNER JOIN roles r ON r.id = u.role_id AND r.role_name = 'admin'
     LEFT JOIN module_admin_assignments maa ON maa.user_id = u.id
     GROUP BY u.id, u.first_name, u.last_name, u.username, u.email, u.account_status
     ORDER BY u.last_name, u.first_name, u.id"
)->fetchAll();

$pendingItems = $pdo->query(
    "SELECT i.id, i.item_name, i.item_type, i.location, i.date_occurred, i.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS reporter_name
     FROM items i
     INNER JOIN users u ON u.id = i.user_id
     WHERE i.status = 'pending'
     ORDER BY i.created_at ASC, i.id ASC
     LIMIT 8"
)->fetchAll();

$pendingReports = $pdo->query(
    "SELECT lr.id, lr.item_id, lr.message, lr.contact_details, lr.created_at,
            i.item_name,
            CONCAT(u.first_name, ' ', u.last_name) AS reporter_name
     FROM lost_item_reports lr
     INNER JOIN items i ON i.id = lr.item_id
     INNER JOIN users u ON u.id = lr.reporter_id
     WHERE lr.status = 'pending'
     ORDER BY lr.created_at ASC, lr.id ASC
     LIMIT 8"
)->fetchAll();

$recentClaims = $pdo->query(
    "SELECT cl.id, cl.status, cl.created_at, i.item_name,
            CONCAT(u.first_name, ' ', u.last_name) AS claimant_name
     FROM claims cl
     INNER JOIN items i ON i.id = cl.item_id
     INNER JOIN users u ON u.id = cl.claimant_id
     ORDER BY cl.created_at DESC, cl.id DESC
     LIMIT 8"
)->fetchAll();

$noticeRows = $pdo->query(
    "SELECT n.id, n.title, n.status, n.priority, n.published_at, n.expires_at,
            n.created_at, n.is_pinned, c.category_name,
            CONCAT(u.first_name, ' ', u.last_name) AS creator_name,
            (SELECT COUNT(*) FROM notice_reads nr WHERE nr.notice_id = n.id) AS read_count
     FROM notices n
     INNER JOIN notice_categories c ON c.id = n.category_id
     INNER JOIN users u ON u.id = n.created_by
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 12"
)->fetchAll();

$pendingApplications = $pdo->query(
    "SELECT a.id, a.headline, a.status, a.created_at,
            CONCAT(u.first_name, ' ', u.last_name) AS applicant_name,
            u.email
     FROM mentor_applications a
     INNER JOIN users u ON u.id = a.user_id
     WHERE a.status = 'pending'
     ORDER BY a.created_at ASC, a.id ASC
     LIMIT 8"
)->fetchAll();
$requestStatuses = $pdo->query(
    'SELECT status, COUNT(*) AS total
     FROM mentorship_requests
     GROUP BY status
     ORDER BY status'
)->fetchAll();
$recentApplications = $pdo->query(
    'SELECT a.id, a.headline, a.status, a.created_at,
            CONCAT(u.first_name, \' \', u.last_name) AS applicant_name
     FROM mentor_applications a
     INNER JOIN users u ON u.id = a.user_id
     ORDER BY a.created_at DESC, a.id DESC
     LIMIT 8'
)->fetchAll();

$activitySearch = is_string($_GET['activity_search'] ?? null)
    ? trim($_GET['activity_search'])
    : '';
$activityAction = is_string($_GET['activity_action'] ?? null)
    ? trim($_GET['activity_action'])
    : '';
$activityConditions = [];
$activityParams = [];
if ($activitySearch !== '') {
    $activityConditions[] = '(a.description LIKE :activity_description
        OR a.action LIKE :activity_action_search
        OR u.username LIKE :activity_username
        OR u.email LIKE :activity_email)';
    $activityTerm = '%' . $activitySearch . '%';
    $activityParams['activity_description'] = $activityTerm;
    $activityParams['activity_action_search'] = $activityTerm;
    $activityParams['activity_username'] = $activityTerm;
    $activityParams['activity_email'] = $activityTerm;
}
if ($activityAction !== '') {
    $activityConditions[] = 'a.action = :selected_activity_action';
    $activityParams['selected_activity_action'] = $activityAction;
}
$activitySql = 'SELECT a.id, a.action, a.description, a.created_at, a.ip_address,
                       CONCAT(u.first_name, \' \', u.last_name) AS actor_name,
                       u.username AS actor_username
                FROM activity a
                LEFT JOIN users u ON u.id = a.user_id';
if ($activityConditions) {
    $activitySql .= ' WHERE ' . implode(' AND ', $activityConditions);
}
$activitySql .= ' ORDER BY a.created_at DESC, a.id DESC LIMIT 100';
$activityStatement = $pdo->prepare($activitySql);
$activityStatement->execute($activityParams);
$activities = $activityStatement->fetchAll();
$activityActions = $pdo->query(
    'SELECT DISTINCT action FROM activity ORDER BY action LIMIT 50'
)->fetchAll(PDO::FETCH_COLUMN);

$notificationRows = $pdo->query(
    'SELECT n.id, n.title, n.message, n.notification_type, n.is_read,
            n.read_at, n.created_at, u.username, u.email
     FROM notification n
     INNER JOIN users u ON u.id = n.user_id
     ORDER BY n.created_at DESC, n.id DESC
     LIMIT 20'
)->fetchAll();
$settings = $pdo->query(
    'SELECT setting_key, setting_value, setting_type, description,
            updated_at, updated_by
     FROM system_settings
     ORDER BY setting_key'
)->fetchAll();

$displayName = trim(
    (string) ($_SESSION['first_name'] ?? '') . ' '
    . (string) ($_SESSION['last_name'] ?? '')
);
if ($displayName === '') {
    $displayName = (string) ($_SESSION['username'] ?? 'Super Admin');
}
$hasPendingApprovals = $counts['pending_items'] + $counts['pending_reports'] + $counts['pending_claims'] + $counts['pending_applications'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Super Admin Control Centre | <?= $escape(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= $escape(SUPERADMIN_URL . '/dashboard.css?v=' . filemtime(__DIR__ . '/dashboard.css')) ?>">
</head>
<body>
<div class="app-shell">
    <aside class="sidebar">
        <a class="brand" href="<?= $escape(SUPERADMIN_URL) ?>/dashboard.php">
            <span class="brand-mark">C</span>
            <span>CampusFind<small>SUPER ADMIN</small></span>
        </a>
        <nav class="side-nav" aria-label="Super Admin sections">
            <a href="#overview">Overview</a>
            <a href="#users">User Management</a>
            <a href="#lost-items">Lost Items <span><?= $counts['pending_items'] + $counts['pending_reports'] ?></span></a>
            <a href="#notices">Notices</a>
            <a href="#mentorship">Mentorship <span><?= $counts['pending_applications'] ?></span></a>
            <a href="#administrators">Administrators</a>
            <a href="#activity">Activity Logs</a>
            <a href="#notifications">Notifications <span><?= $counts['unread_notifications'] ?></span></a>
            <a href="#settings">System Settings</a>
        </nav>
        <div class="sidebar-bottom">
            <div class="sidebar-user">
                <span class="avatar"><?= $escape(strtoupper(substr($displayName, 0, 1))) ?></span>
                <span><?= $escape($displayName) ?><small>Super Admin</small></span>
            </div>
            <form method="post" action="<?= $escape(BASE_URL) ?>/logout.php">
                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                <button class="logout-button" type="submit">Sign out</button>
            </form>
        </div>
    </aside>

    <main class="main-content" id="overview">
        <header class="topbar">
            <div>
                <p class="eyebrow">CAMPUSFIND / SYSTEM ADMINISTRATION</p>
                <h1>Control centre</h1>
                <p class="subheading">A live overview of users, services, and administrative work.</p>
            </div>
            <div class="topbar-actions">
                <span class="live-indicator">Database connected</span>
                <a class="button button-primary" href="<?= $escape(SUPERADMIN_URL) ?>/admins/create.php">+ Create administrator</a>
            </div>
        </header>

        <?php if (is_array($flash) && isset($flash['message'], $flash['kind'])): ?>
            <div class="alert alert-<?= $escape($flash['kind'] === 'error' ? 'error' : 'success') ?>" role="<?= $flash['kind'] === 'error' ? 'alert' : 'status' ?>">
                <?= $escape($flash['message']) ?>
            </div>
        <?php endif; ?>

        <section class="alert-stack" aria-label="System alerts">
            <?php if ($hasPendingApprovals > 0): ?>
                <div class="system-alert alert-warn">
                    <span class="alert-icon">!</span>
                    <span><strong><?= $hasPendingApprovals ?> pending decision<?= $hasPendingApprovals === 1 ? '' : 's' ?></strong><small><?= $counts['pending_items'] ?> item submissions · <?= $counts['pending_reports'] ?> content flags · <?= $counts['pending_claims'] ?> claims · <?= $counts['pending_applications'] ?> mentor applications</small></span>
                    <a href="#lost-items">Review queue</a>
                </div>
            <?php endif; ?>
            <?php if ($counts['unassigned_admins'] > 0): ?>
                <div class="system-alert alert-info">
                    <span class="alert-icon">i</span>
                    <span><strong><?= $counts['unassigned_admins'] ?> active administrator<?= $counts['unassigned_admins'] === 1 ? '' : 's' ?> without module assignments</strong><small>These accounts cannot enter a module-admin area.</small></span>
                    <a href="#administrators">Assign modules</a>
                </div>
            <?php endif; ?>
            <?php if ($counts['draft_notices'] > 0): ?>
                <div class="system-alert alert-info">
                    <span class="alert-icon">i</span>
                    <span><strong><?= $counts['draft_notices'] ?> notice draft<?= $counts['draft_notices'] === 1 ? '' : 's' ?></strong><small>Notices use draft, published, and archived states; there is no separate approval state.</small></span>
                    <a href="#notices">Review notices</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="section-block">
            <div class="section-heading">
                <div><p class="eyebrow">SYSTEM SNAPSHOT</p><h2>CampusFind at a glance</h2></div>
                <span class="updated-label">Live totals</span>
            </div>
            <div class="stats-grid">
                <article class="stat-card stat-users"><span class="stat-icon">U</span><p>Total accounts</p><strong><?= number_format($counts['users']) ?></strong><small><?= number_format($counts['active_users']) ?> active · <?= number_format($counts['suspended_users']) ?> suspended</small></article>
                <article class="stat-card stat-admin"><span class="stat-icon">A</span><p>Administrators</p><strong><?= number_format($counts['admins']) ?></strong><small><?= number_format($counts['superadmins']) ?> Super Admin<?= $counts['superadmins'] === 1 ? '' : 's' ?></small></article>
                <article class="stat-card stat-lost"><span class="stat-icon">L</span><p>Lost Items</p><strong><?= number_format($counts['items']) ?></strong><small><?= number_format($counts['pending_items']) ?> submissions pending · <?= number_format($counts['recovered_items']) ?> recovered · <?= number_format($counts['reports']) ?> content flags</small></article>
                <article class="stat-card stat-notices"><span class="stat-icon">N</span><p>Campus Notices</p><strong><?= number_format($counts['notices']) ?></strong><small><?= number_format($counts['published_notices']) ?> currently published · <?= number_format($counts['draft_notices']) ?> drafts</small></article>
                <article class="stat-card stat-mentorship"><span class="stat-icon">M</span><p>Mentorship</p><strong><?= number_format($counts['mentor_profiles']) ?></strong><small><?= number_format($counts['mentor_applications']) ?> applications · <?= number_format($counts['pending_applications']) ?> pending</small></article>
                <article class="stat-card stat-queue"><span class="stat-icon">!</span><p>Pending decisions</p><strong><?= number_format($hasPendingApprovals) ?></strong><small>Item submissions, content flags, claims, and mentor applications</small></article>
            </div>
        </section>

        <section class="overview-grid section-block">
            <article class="panel">
                <div class="panel-heading"><div><p class="eyebrow">LATEST</p><h2>Recent registrations</h2></div><a href="#users">Manage users</a></div>
                <?php if (!$recentUsers): ?>
                    <p class="empty-state">No user accounts found.</p>
                <?php else: ?>
                    <div class="compact-list">
                        <?php foreach ($recentUsers as $user): ?>
                            <div class="compact-row">
                                <span class="avatar avatar-small"><?= $escape(strtoupper(substr((string) $user['first_name'], 0, 1))) ?></span>
                                <span class="compact-main"><strong><?= $escape(trim($user['first_name'] . ' ' . $user['last_name'])) ?></strong><small><?= $escape($user['email']) ?></small></span>
                                <span class="pill"><?= $escape(str_replace('_', ' ', $user['role_name'])) ?></span>
                                <time><?= $escape($user['created_at']) ?></time>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
            <article class="panel">
                <div class="panel-heading"><div><p class="eyebrow">AUDIT TRAIL</p><h2>Recent admin activity</h2></div><a href="#activity">View logs</a></div>
                <?php if (!$recentActivities): ?>
                    <p class="empty-state">No activity events have been recorded yet.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($recentActivities as $activity): ?>
                            <div class="timeline-row">
                                <span class="timeline-dot"></span>
                                <div><strong><?= $escape($activity['action']) ?></strong><p><?= $escape($activity['description'] ?: 'No event details recorded.') ?></p><small><?= $escape($activity['actor_name'] ?: $activity['actor_username'] ?: 'System') ?> · <?= $escape($activity['created_at']) ?></small></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <p class="data-note">This log contains recorded events only. Login and logout events are not currently written to the activity table.</p>
            </article>
        </section>

        <section class="section-block panel" id="users">
            <div class="section-heading">
                <div><p class="eyebrow">IDENTITY & ACCESS</p><h2>User management</h2><p class="section-description">Search registered account identifiers, review status, and make guarded role/status changes.</p></div>
                <a class="button button-primary" href="<?= $escape(SUPERADMIN_URL) ?>/admins/create.php">Create administrator</a>
            </div>
            <form class="filter-form" method="get" action="dashboard.php#users">
                <input type="search" name="user_search" value="<?= $escape($userSearch) ?>" placeholder="Name, email, username, or phone">
                <select name="user_role"><option value="">All roles</option><?php foreach ($roleNames as $roleName): ?><option value="<?= $escape($roleName) ?>" <?= $userRoleFilter === $roleName ? 'selected' : '' ?>><?= $escape(ucwords(str_replace('_', ' ', $roleName))) ?></option><?php endforeach; ?></select>
                <select name="user_status"><option value="">All statuses</option><?php foreach ($accountStatuses as $accountStatus): ?><option value="<?= $escape($accountStatus) ?>" <?= $userStatusFilter === $accountStatus ? 'selected' : '' ?>><?= $escape(ucfirst($accountStatus)) ?></option><?php endforeach; ?></select>
                <select name="user_module"><option value="">Any module assignment</option><?php foreach ($moduleLabels as $key => $label): ?><option value="<?= $escape($key) ?>" <?= $userModuleFilter === $key ? 'selected' : '' ?>><?= $escape($label) ?></option><?php endforeach; ?></select>
                <button class="button button-secondary" type="submit">Filter</button>
                <a class="button button-quiet" href="dashboard.php#users">Reset</a>
            </form>
            <p class="table-note">Showing up to 100 matches. Registration numbers are not stored in the current users schema.</p>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>User</th><th>Role / status</th><th>Affiliation</th><th>Modules</th><th>Joined / last login</th><th>Account controls</th></tr></thead>
                    <tbody>
                    <?php if (!$users): ?>
                        <tr><td class="empty-cell" colspan="6">No users match these filters.</td></tr>
                    <?php else: foreach ($users as $user): ?>
                        <?php $userName = trim($user['first_name'] . ' ' . $user['last_name']); ?>
                        <tr>
                            <td><strong><?= $escape($userName !== '' ? $userName : $user['username']) ?></strong><small><?= $escape($user['email']) ?></small><small>@<?= $escape($user['username']) ?><?= $user['phone'] ? ' · ' . $escape($user['phone']) : '' ?></small></td>
                            <td><span class="pill"><?= $escape(ucwords(str_replace('_', ' ', $user['role_name']))) ?></span><span class="status status-<?= $escape($user['account_status']) ?>"><?= $escape(ucfirst($user['account_status'])) ?></span></td>
                            <td><?= $escape($user['school_name'] ?: '—') ?><small><?= $escape($user['department_name'] ?: '') ?></small></td>
                            <td><?= $escape(implode(', ', array_map(static fn (string $key): string => $moduleLabels[$key] ?? $key, array_filter(explode(',', (string) $user['modules'])))) ?: '—') ?></td>
                            <td><?= $escape($user['created_at']) ?><small>Last login: <?= $escape($user['last_login_at'] ?: 'Not recorded') ?></small></td>
                            <td>
                                <?php if ((int) $user['id'] === $currentUserId || $user['role_name'] === 'super_admin'): ?>
                                    <span class="data-note">Protected account</span>
                                <?php else: ?>
                                    <div class="row-controls">
                                        <form method="post" action="dashboard.php#users" class="inline-control" onsubmit="return confirm('Change this account status?');">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="user_status"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="return_section" value="users">
                                            <select name="account_status" aria-label="Account status for <?= $escape($user['username']) ?>"><?php foreach ($accountStatuses as $accountStatus): ?><option value="<?= $escape($accountStatus) ?>" <?= $user['account_status'] === $accountStatus ? 'selected' : '' ?>><?= $escape(ucfirst($accountStatus)) ?></option><?php endforeach; ?></select>
                                            <button class="mini-button" type="submit">Save status</button>
                                        </form>
                                        <?php if ($user['role_name'] !== 'super_admin'): ?>
                                        <form method="post" action="dashboard.php#users" class="inline-control" onsubmit="return confirm('Change this account role? Administrator assignments will be removed when an administrator is demoted.');">
                                            <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="user_role"><input type="hidden" name="user_id" value="<?= (int) $user['id'] ?>"><input type="hidden" name="return_section" value="users">
                                            <select name="role_name" aria-label="Role for <?= $escape($user['username']) ?>">
                                                <?php foreach (['student', 'staff'] as $roleName): ?><option value="<?= $escape($roleName) ?>" <?= $user['role_name'] === $roleName ? 'selected' : '' ?>><?= $escape($user['role_name'] === 'admin' ? 'Demote to ' . $roleName : ucfirst($roleName)) ?></option><?php endforeach; ?>
                                            </select>
                                            <button class="mini-button" type="submit">Change role</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            <p class="data-note">Super Admin roles cannot be changed here. Administrator accounts are created through the dedicated registration flow; role changes cannot promote a user to admin.</p>
        </section>

        <section class="section-block panel" id="lost-items">
            <div class="section-heading"><div><p class="eyebrow">LOST ITEMS</p><h2>Reports &amp; claims</h2><p class="section-description"><?= number_format($counts['pending_items'] + $counts['pending_reports']) ?> item submissions/reports and <?= number_format($counts['pending_claims']) ?> claims are pending review.</p></div><a class="button button-secondary" href="<?= $escape(APP_BASE_PATH . '/lostandfound/admin/manage-items.php') ?>">Manage all items</a></div>
            <h3 class="subsection-title">Pending item reports</h3>
            <div class="table-wrap"><table><thead><tr><th>Report</th><th>Type</th><th>Reporter</th><th>Location / date</th><th>Submitted</th><th>Action</th></tr></thead><tbody>
                <?php if (!$pendingItems): ?><tr><td colspan="6" class="empty-cell">No pending item reports.</td></tr><?php else: foreach ($pendingItems as $item): ?>
                    <tr><td><strong><?= $escape($item['item_name']) ?></strong><small>Report #<?= (int) $item['id'] ?></small></td><td><span class="pill"><?= $escape(ucfirst($item['item_type'])) ?></span></td><td><?= $escape($item['reporter_name']) ?></td><td><?= $escape($item['location']) ?><small><?= $escape($item['date_occurred']) ?></small></td><td><?= $escape($item['created_at']) ?></td><td><a class="mini-link" href="<?= $escape(APP_BASE_PATH . '/lostandfound/admin/manage-items.php?status=pending') ?>">Review report</a></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <h3 class="subsection-title">Pending content flags</h3>
            <div class="table-wrap"><table><thead><tr><th>Report</th><th>Reporter</th><th>Message / contact</th><th>Submitted</th><th>Decision</th></tr></thead><tbody>
                <?php if (!$pendingReports): ?><tr><td colspan="5" class="empty-cell">No pending content flags.</td></tr><?php else: foreach ($pendingReports as $report): ?>
                    <tr><td><strong><?= $escape($report['item_name']) ?></strong><small>Report #<?= (int) $report['id'] ?> · Item #<?= (int) $report['item_id'] ?></small></td><td><?= $escape($report['reporter_name']) ?></td><td><?= $escape($report['message']) ?><small><?= $escape($report['contact_details'] ?: 'No contact supplied') ?></small></td><td><?= $escape($report['created_at']) ?></td><td><div class="row-controls">
                        <form method="post" action="dashboard.php#lost-items" onsubmit="return confirm('Approve this lost-item report?');"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="report_decision"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input type="hidden" name="decision" value="approved"><input type="hidden" name="return_section" value="lost-items"><button class="mini-button" type="submit">Approve</button></form>
                        <form method="post" action="dashboard.php#lost-items" onsubmit="return confirm('Reject this lost-item report?');"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="report_decision"><input type="hidden" name="report_id" value="<?= (int) $report['id'] ?>"><input type="hidden" name="decision" value="rejected"><input type="hidden" name="return_section" value="lost-items"><input class="reason-input" type="text" name="rejection_reason" maxlength="5000" placeholder="Reason required" aria-label="Reason for rejecting report #<?= (int) $report['id'] ?>" required><button class="mini-button mini-danger" type="submit">Reject</button></form>
                    </div></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <h3 class="subsection-title">Recent claims</h3>
            <div class="table-wrap"><table><thead><tr><th>Claimant</th><th>Item</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php if (!$recentClaims): ?><tr><td colspan="5" class="empty-cell">No claims have been submitted.</td></tr><?php else: foreach ($recentClaims as $claim): ?>
                    <tr><td><?= $escape($claim['claimant_name']) ?></td><td><?= $escape($claim['item_name']) ?></td><td><?= $escape($claim['created_at']) ?></td><td><span class="status status-<?= $escape($claim['status']) ?>"><?= $escape(ucfirst($claim['status'])) ?></span></td><td><a class="mini-link" href="<?= $escape(APP_BASE_PATH . '/lostandfound/admin/review-claim.php?id=' . (int) $claim['id']) ?>">Review claim</a></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>

        <section class="section-block panel" id="notices">
            <div class="section-heading"><div><p class="eyebrow">CAMPUS NOTICES</p><h2>Notice oversight</h2><p class="section-description">Published, draft, scheduled, expired, and archived notices; reads are counted from notice_reads.</p></div><a class="button button-secondary" href="<?= $escape(APP_BASE_PATH . '/notices/admin/') ?>">Open Notices administration</a></div>
            <div class="table-wrap"><table><thead><tr><th>Notice</th><th>Creator</th><th>State</th><th>Priority</th><th>Reads</th><th>Created</th><th>Actions</th></tr></thead><tbody>
                <?php if (!$noticeRows): ?><tr><td colspan="7" class="empty-cell">No notices have been created.</td></tr><?php else: foreach ($noticeRows as $notice): ?>
                    <?php $displayStatus = $notice['status']; if ($notice['status'] === 'published' && $notice['published_at'] > date('Y-m-d H:i:s')) { $displayStatus = 'scheduled'; } elseif ($notice['status'] === 'published' && $notice['expires_at'] !== null && $notice['expires_at'] < date('Y-m-d H:i:s')) { $displayStatus = 'expired'; } ?>
                    <tr><td><strong><?= $escape($notice['title']) ?></strong><small><?= $escape($notice['category_name']) ?><?= (int) $notice['is_pinned'] === 1 ? ' · Pinned' : '' ?></small></td><td><?= $escape($notice['creator_name']) ?></td><td><span class="status status-<?= $escape($displayStatus) ?>"><?= $escape(ucfirst($displayStatus)) ?></span></td><td><?= $escape(ucfirst($notice['priority'])) ?></td><td><?= (int) $notice['read_count'] ?></td><td><?= $escape($notice['created_at']) ?></td><td><div class="row-controls"><a class="mini-link" href="<?= $escape(APP_BASE_PATH . '/notices/admin/edit-announcement.php?id=' . (int) $notice['id']) ?>">View / edit</a>
                    <?php if ($notice['status'] === 'draft'): ?><form method="post" action="<?= $escape(APP_BASE_PATH . '/notices/admin/action.php') ?>" onsubmit="return confirm('Publish this notice now?');"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>"><input type="hidden" name="action" value="publish"><button class="mini-button" type="submit">Publish</button></form>
                    <?php elseif ($notice['status'] === 'published'): ?><form method="post" action="<?= $escape(APP_BASE_PATH . '/notices/admin/action.php') ?>" onsubmit="return confirm('Archive this notice?');"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="notice_id" value="<?= (int) $notice['id'] ?>"><input type="hidden" name="action" value="archive"><button class="mini-button mini-danger" type="submit">Archive</button></form><?php endif; ?>
                    </div></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <p class="data-note">The Notices schema has no rejected or approval state. Super Admin moderation uses the existing draft/publish/archive workflow; notice deletion is not offered.</p>
        </section>

        <section class="section-block panel" id="mentorship">
            <div class="section-heading"><div><p class="eyebrow">MENTORSHIP</p><h2>Applications &amp; connections</h2><p class="section-description"><?= number_format($counts['pending_applications']) ?> mentor applications await review; <?= number_format($counts['mentor_profiles']) ?> mentor profiles are active.</p></div><a class="button button-secondary" href="<?= $escape(APP_BASE_PATH) ?>/mentorship/admin/applications.php">Open applications</a></div>
            <div class="status-summary"><?php foreach ($requestStatuses as $statusRow): ?><span class="summary-chip"><?= $escape(ucfirst($statusRow['status'])) ?><strong><?= (int) $statusRow['total'] ?></strong></span><?php endforeach; ?></div>
            <div class="table-wrap"><table><thead><tr><th>Applicant</th><th>Headline</th><th>Submitted</th><th>Status</th><th>Action</th></tr></thead><tbody>
                <?php if (!$recentApplications): ?><tr><td colspan="5" class="empty-cell">No mentorship applications.</td></tr><?php else: foreach ($recentApplications as $application): ?>
                    <tr><td><?= $escape($application['applicant_name']) ?></td><td><?= $escape($application['headline']) ?></td><td><?= $escape($application['created_at']) ?></td><td><span class="status status-<?= $escape($application['status']) ?>"><?= $escape(ucfirst($application['status'])) ?></span></td><td><a class="mini-link" href="<?= $escape(APP_BASE_PATH . '/mentorship/admin/review.php?id=' . (int) $application['id']) ?>"><?= $application['status'] === 'pending' ? 'Review application' : 'View application' ?></a></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <p class="data-note">Application decisions use the existing mentorship review workflow, including profile creation and applicant notification on approval.</p>
            <h3 class="subsection-title">Mentor profiles</h3>
            <div class="table-wrap"><table><thead><tr><th>Mentor</th><th>Profile</th><th>Expertise</th><th>Availability</th><th>Created</th></tr></thead><tbody>
                <?php if (!$mentorProfiles): ?><tr><td colspan="5" class="empty-cell">No mentor profiles have been created.</td></tr><?php else: foreach ($mentorProfiles as $mentor): ?>
                    <?php $mentorName = trim($mentor['first_name'] . ' ' . $mentor['last_name']); ?>
                    <tr><td><?= $escape($mentorName !== '' ? $mentorName : $mentor['username']) ?><small>@<?= $escape($mentor['username']) ?></small></td><td><?= $escape($mentor['headline'] ?: 'No headline') ?><small><?= $escape($mentor['bio'] ?: 'No biography provided') ?></small><small><span class="status status-<?= $escape($mentor['status']) ?>"><?= $escape(ucfirst($mentor['status'])) ?></span></small></td><td><?= $escape($mentor['expertise'] ?: 'Not specified') ?></td><td><span class="status <?= (int) $mentor['accepting_requests'] === 1 ? 'status-active' : 'status-inactive' ?>"><?= (int) $mentor['accepting_requests'] === 1 ? 'Accepting' : 'Unavailable' ?></span></td><td><?= $escape($mentor['created_at']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <h3 class="subsection-title">Recent mentorship requests</h3>
            <div class="table-wrap"><table><thead><tr><th>Mentee</th><th>Mentor</th><th>Request</th><th>Status</th><th>Submitted</th></tr></thead><tbody>
                <?php if (!$mentorshipRequests): ?><tr><td colspan="5" class="empty-cell">No mentorship requests have been submitted.</td></tr><?php else: foreach ($mentorshipRequests as $request): ?>
                    <?php $studentName = trim($request['student_name']); $mentorName = trim($request['mentor_name']); ?>
                    <tr><td><?= $escape($studentName !== '' ? $studentName : $request['student_username']) ?><small>@<?= $escape($request['student_username']) ?></small></td><td><?= $escape($mentorName !== '' ? $mentorName : $request['mentor_username']) ?><small><?= $escape($request['mentor_headline'] ?: 'Mentor profile') ?></small></td><td><?= $escape($request['message']) ?><?php if ($request['mentor_response']): ?><small>Mentor response: <?= $escape($request['mentor_response']) ?></small><?php endif; ?></td><td><span class="status status-<?= $escape($request['status']) ?>"><?= $escape(ucfirst($request['status'])) ?></span></td><td><?= $escape($request['created_at']) ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>

        <section class="section-block panel" id="administrators">
            <div class="section-heading"><div><p class="eyebrow">ADMIN ACCESS</p><h2>Module administrator assignments</h2><p class="section-description">An active administrator may be assigned to any combination of modules. Super Admin accounts have unrestricted access and do not need assignments.</p></div><a class="button button-primary" href="<?= $escape(SUPERADMIN_URL) ?>/admins/create.php">+ Register administrator</a></div>
            <div class="admin-grid">
                <?php if (!$admins): ?><p class="empty-state">No module administrator accounts exist yet.</p><?php else: foreach ($admins as $admin): ?>
                    <?php $adminModules = array_filter(explode(',', (string) $admin['modules'])); $adminName = trim($admin['first_name'] . ' ' . $admin['last_name']); ?>
                    <article class="admin-card">
                        <div class="admin-card-head"><span class="avatar"><?= $escape(strtoupper(substr($adminName !== '' ? $adminName : $admin['username'], 0, 1))) ?></span><span><strong><?= $escape($adminName !== '' ? $adminName : $admin['username']) ?></strong><small><?= $escape($admin['email']) ?> · <?= $escape(ucfirst($admin['account_status'])) ?></small></span></div>
                        <?php if ($admin['account_status'] === 'active'): ?>
                            <form method="post" action="dashboard.php#administrators">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="admin_modules"><input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>"><input type="hidden" name="return_section" value="administrators">
                                <fieldset><legend>Assigned modules</legend>
                                    <?php foreach ($moduleLabels as $key => $label): ?><label class="check-row"><input type="checkbox" name="modules[]" value="<?= $escape($key) ?>" <?= in_array($key, $adminModules, true) ? 'checked' : '' ?>><span><?= $escape($label) ?></span></label><?php endforeach; ?>
                                </fieldset>
                                <button class="button button-secondary" type="submit">Save assignments</button>
                            </form>
                        <?php elseif ($adminModules): ?>
                            <p class="data-note">Inactive accounts cannot receive new access. Current assignments: <?= $escape(implode(', ', array_map(static fn (string $key): string => $moduleLabels[$key] ?? $key, $adminModules))) ?>.</p>
                            <form method="post" action="dashboard.php#administrators">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="admin_modules"><input type="hidden" name="user_id" value="<?= (int) $admin['id'] ?>"><input type="hidden" name="return_section" value="administrators">
                                <button class="mini-button mini-danger" type="submit">Remove assignments</button>
                            </form>
                        <?php else: ?>
                            <p class="data-note">Activate this account before assigning module access.</p>
                        <?php endif; ?>
                    </article>
                <?php endforeach; endif; ?>
            </div>
        </section>

        <section class="section-block panel" id="activity">
            <div class="section-heading"><div><p class="eyebrow">AUDIT &amp; ACCOUNTABILITY</p><h2>System activity</h2><p class="section-description">Only events actually written to the activity table are displayed.</p></div></div>
            <form class="filter-form" method="get" action="dashboard.php#activity">
                <input type="search" name="activity_search" value="<?= $escape($activitySearch) ?>" placeholder="Search event, user, or description">
                <select name="activity_action"><option value="">All event types</option><?php foreach ($activityActions as $availableAction): ?><option value="<?= $escape($availableAction) ?>" <?= $activityAction === $availableAction ? 'selected' : '' ?>><?= $escape($availableAction) ?></option><?php endforeach; ?></select>
                <button class="button button-secondary" type="submit">Filter logs</button><a class="button button-quiet" href="dashboard.php#activity">Reset</a>
            </form>
            <div class="table-wrap"><table><thead><tr><th>Time</th><th>Event</th><th>Description</th><th>Actor</th><th>IP address</th></tr></thead><tbody>
                <?php if (!$activities): ?><tr><td colspan="5" class="empty-cell">No activity matches these filters.</td></tr><?php else: foreach ($activities as $activity): ?>
                    <tr><td><?= $escape($activity['created_at']) ?></td><td><span class="pill"><?= $escape($activity['action']) ?></span></td><td><?= $escape($activity['description'] ?: '—') ?></td><td><?= $escape($activity['actor_name'] ?: $activity['actor_username'] ?: 'System') ?></td><td><?= $escape($activity['ip_address'] ?: '—') ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
        </section>

        <section class="section-block panel" id="notifications">
            <div class="section-heading"><div><p class="eyebrow">USER COMMUNICATION</p><h2>Notifications</h2><p class="section-description"><?= number_format($counts['unread_notifications']) ?> unread notifications across all accounts.</p></div></div>
            <div class="table-wrap"><table><thead><tr><th>Created</th><th>Recipient</th><th>Notification</th><th>Type</th><th>Read status</th></tr></thead><tbody>
                <?php if (!$notificationRows): ?><tr><td colspan="5" class="empty-cell">No notifications have been created.</td></tr><?php else: foreach ($notificationRows as $notification): ?>
                    <tr><td><?= $escape($notification['created_at']) ?></td><td><?= $escape($notification['username']) ?><small><?= $escape($notification['email']) ?></small></td><td><strong><?= $escape($notification['title']) ?></strong><small><?= $escape($notification['message']) ?></small></td><td><?= $escape(ucfirst($notification['notification_type'])) ?></td><td><span class="status <?= (int) $notification['is_read'] === 1 ? 'status-active' : 'status-pending' ?>"><?= (int) $notification['is_read'] === 1 ? 'Read' : 'Unread' ?></span><?= $notification['read_at'] ? '<small>' . $escape($notification['read_at']) . '</small>' : '' ?></td></tr>
                <?php endforeach; endif; ?>
            </tbody></table></div>
            <p class="data-note">The schema records read state, but does not track delivery or failure status.</p>
        </section>

        <section class="section-block panel" id="settings">
            <div class="section-heading"><div><p class="eyebrow">SYSTEM CONFIGURATION</p><h2>Stored settings</h2><p class="section-description">Current values from system_settings; shown read-only because the application does not currently consume these settings.</p></div></div>
            <div class="settings-grid">
                <?php if (!$settings): ?><p class="empty-state">No system settings are defined.</p><?php else: foreach ($settings as $setting): ?>
                    <article class="setting-card"><div><strong><?= $escape(str_replace('_', ' ', $setting['setting_key'])) ?></strong><small><?= $escape($setting['description'] ?: 'No description') ?></small></div><span class="setting-value"><?= $escape($setting['setting_value'] ?? '—') ?></span></article>
                <?php endforeach; endif; ?>
            </div>
            <?php if ($settings): ?><p class="data-note">These are stored configuration records, not effective controls. Editing them here would imply behavior that the application does not implement.</p><?php endif; ?>
        </section>

        <footer class="page-footer">CampusFind Super Admin · Reporting live database records only</footer>
    </main>
</div>
</body>
</html>