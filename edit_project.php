<?php
session_start();
require 'config.php'; // Ensure this file properly connects to your database using PDO

// Check that the user is logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Get the projectID from GET parameters.
$projectID = isset($_GET['projectID']) ? intval($_GET['projectID']) : 0;
if ($projectID <= 0) {
    die("Invalid Project ID");
}

// Define the ordered list of stages. This should be consistent across index.php and edit_project.php
$stagesOrder = [
    'Purchase Request',
    'RFQ 1',
    'RFQ 2',
    'RFQ 3',
    'Abstract of Quotation',
    'Purchase Order',
    'Notice of Award',
    'Notice to Proceed'
];

// Fetch project details along with creator data.
$stmt = $pdo->prepare("SELECT p.*, u.firstname, u.lastname, o.officename
                        FROM tblproject p
                        LEFT JOIN tbluser u ON p.userID = u.userID
                        LEFT JOIN officeid o ON u.officeID = o.officeID
                        WHERE p.projectID = ?");
$stmt->execute([$projectID]);
$project = $stmt->fetch();
if (!$project) {
    die("Project not found");
}

// Retrieve stages for the project.
$stmt2 = $pdo->prepare("SELECT * FROM tblproject_stages
                         WHERE projectID = ?
                         ORDER BY FIELD(stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed')");
$stmt2->execute([$projectID]);
$stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

// If no stages exist, create records for every stage.
if (empty($stages)) {
    foreach ($stagesOrder as $stageName) {
        $insertCreatedAt = null;
        if ($stageName === 'Purchase Request') {
            $insertCreatedAt = date("Y-m-d H:i:s");
        }
        $stmtInsert = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, office, createdAt) VALUES (?, ?, ?, ?)");
        $stmtInsert->execute([$projectID, $stageName, "", $insertCreatedAt]);
    }
    // Re-fetch stages after creation
    $stmt2->execute([$projectID]);
    $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);
}

// Map stages by stageName for easy access and find the last submitted stage.
$stagesMap = [];
$noticeToProceedSubmitted = false;
$lastSubmittedStageIndex = -1;

foreach ($stages as $index => $s) {
    $stagesMap[$s['stageName']] = $s;
    if ($s['isSubmitted'] == 1) {
        $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
        if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
            $lastSubmittedStageIndex = $stageIndexInOrder;
        }
    }
    if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
        $noticeToProceedSubmitted = true;
    }
}
$lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;


// Process Project Header update (available only for admins).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_project_header'])) {
    if ($_SESSION['admin'] == 1) {
        $prNumber = trim($_POST['prNumber']);
        $projectDetails = trim($_POST['projectDetails']);
        if (empty($prNumber) || empty($projectDetails)) {
            $errorHeader = "PR Number and Project Details are required.";
        } else {
            $stmtUpdate = $pdo->prepare("UPDATE tblproject
                                          SET prNumber = ?, projectDetails = ?, editedAt = CURRENT_TIMESTAMP, editedBy = ?,
                                              lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ?
                                          WHERE projectID = ?");
            $stmtUpdate->execute([$prNumber, $projectDetails, $_SESSION['userID'], $_SESSION['userID'], $projectID]);
            $successHeader = "Project updated successfully.";
            // Reload the updated project details.
            $stmt->execute([$projectID]);
            $project = $stmt->fetch();
        }
    }
}

// Process individual stage submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_stage'])) {
    $stageName = $_POST['stageName'];
    $safeStage = str_replace(' ', '_', $stageName);

    $currentStageDataForPost = $stagesMap[$stageName] ?? null;
    $currentIsSubmittedInDB = ($currentStageDataForPost && $currentStageDataForPost['isSubmitted'] == 1);

    // Retrieve new inputs from datetime-local fields.
    // For created field, take value from form if admin, otherwise use DB value if existing, or current time if submitting.
    $formCreated = isset($_POST["created_$safeStage"]) && !empty($_POST["created_$safeStage"]) ? $_POST["created_$safeStage"] : null;
    $approvedAt = isset($_POST['approvedAt']) && !empty($_POST['approvedAt']) ? $_POST['approvedAt'] : null;
    $office = isset($_POST["office_$safeStage"]) ? trim($_POST["office_$safeStage"]) : "";
    $remark = isset($_POST['remark']) ? trim($_POST['remark']) : "";

    // Determine if this is a "Submit" or "Unsubmit" action
    $isSubmittedVal = 1; // Default to submit
    if ($_SESSION['admin'] == 1 && $currentIsSubmittedInDB && $stageName === $lastSubmittedStageName) {
        // If admin is unsubmitting, and it's the last submitted stage, set isSubmitted to 0
        $isSubmittedVal = 0;
    }

    // --- Validation Logic ---
    $validationFailed = false;
    if ($isSubmittedVal == 1) { // Only validate on "Submit" action
        // 'Approved', 'Office', and 'Remark' are always required for submission
        if (empty($approvedAt) || empty($office) || empty($remark)) {
            $validationFailed = true;
        }
        // 'Created' is required for admin submission (except PR, which is auto-set)
        // Now, it's explicitly stated that Created is NOT required for PR even for admin
        if ($_SESSION['admin'] == 1 && $stageName !== 'Purchase Request' && empty($formCreated)) {
            $validationFailed = true;
        }
    }

    if ($validationFailed) {
        $stageError = "All fields (Approved, Office, and Remark) are required for stage '$stageName' to be submitted.";
        if ($_SESSION['admin'] == 1 && $stageName !== 'Purchase Request' && empty($formCreated)) {
            $stageError = "All fields (Created, Approved, Office, and Remark) are required for stage '$stageName' to be submitted.";
        }
    } else {
        // Prepare createdAt for update:
        $currentCreatedAtInDB = $currentStageDataForPost['createdAt'] ?? null;
        $actualCreatedAt = $currentCreatedAtInDB; // Start with the existing value from DB

        if ($_SESSION['admin'] == 1) {
            // If admin, they can override the value if they provide one
            // BUT NOT for 'Purchase Request' stage (as per new rule)
            if ($stageName !== 'Purchase Request' && !empty($formCreated)) {
                $actualCreatedAt = $formCreated;
            } else if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB) && $stageName !== 'Purchase Request') {
                // If admin submitting and field was empty, auto-set to now (except for PR)
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
            // For 'Purchase Request', createdAt should remain its initial auto-set value or null if not yet set.
            // Admins cannot change it.
        } else {
            // Non-admin: The field is disabled for them.
            // It should be auto-populated if submitting and currently empty.
            if ($isSubmittedVal == 1 && empty($currentCreatedAtInDB)) {
                $actualCreatedAt = date("Y-m-d H:i:s");
            }
        }

        // Convert datetime-local values ("Y-m-d\TH:i") to MySQL datetime ("Y-m-d H:i:s").
        $created_dt = $actualCreatedAt ? date("Y-m-d H:i:s", strtotime($actualCreatedAt)) : null;
        // If unsubmitting, clear approvedAt, office, and remarks
        if ($isSubmittedVal == 0) {
            $approved_dt = null;
            $office = "";
            $remark = "";
        } else {
            $approved_dt = $approvedAt ? date("Y-m-d H:i:s", strtotime($approvedAt)) : null;
        }


        $stmtStageUpdate = $pdo->prepare("UPDATE tblproject_stages
                                           SET createdAt = ?, approvedAt = ?, office = ?, remarks = ?, isSubmitted = ?
                                           WHERE projectID = ? AND stageName = ?");
        $stmtStageUpdate->execute([$created_dt, $approved_dt, $office, $remark, $isSubmittedVal, $projectID, $stageName]);
        $stageSuccess = "Stage '$stageName' updated successfully.";

        // If this is a "Submit" action (isSubmittedVal == 1), auto-update the next stage's createdAt if empty.
        if ($isSubmittedVal == 1) {
            $index = array_search($stageName, $stagesOrder);
            if ($index !== false && $index < count($stagesOrder) - 1) {
                $nextStageName = $stagesOrder[$index + 1];
                // Only update if the next stage's createdAt is currently empty or null
                if (!(isset($stagesMap[$nextStageName]) && !empty($stagesMap[$nextStageName]['createdAt']))) {
                    $now = date("Y-m-d H:i:s");
                    $stmtNext = $pdo->prepare("UPDATE tblproject_stages SET createdAt = ? WHERE projectID = ? AND stageName = ?");
                    $stmtNext->execute([$now, $projectID, $nextStageName]);
                }
            }
        }

        // Refresh stage records and re-calculate lastSubmittedStageIndex.
        $stmt2->execute([$projectID]);
        $stages = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        $stagesMap = [];
        $noticeToProceedSubmitted = false;
        $lastSubmittedStageIndex = -1; // Reset and recalculate

        foreach ($stages as $s) {
            $stagesMap[$s['stageName']] = $s;
            if ($s['isSubmitted'] == 1) {
                $stageIndexInOrder = array_search($s['stageName'], $stagesOrder);
                if ($stageIndexInOrder !== false && $stageIndexInOrder > $lastSubmittedStageIndex) {
                    $lastSubmittedStageIndex = $stageIndexInOrder;
                }
            }
            if ($s['stageName'] === 'Notice to Proceed' && $s['isSubmitted'] == 1) {
                $noticeToProceedSubmitted = true;
            }
        }
        $lastSubmittedStageName = ($lastSubmittedStageIndex !== -1) ? $stagesOrder[$lastSubmittedStageIndex] : null;


        // Update project's last accessed fields.
        $pdo->prepare("UPDATE tblproject SET lastAccessedAt = CURRENT_TIMESTAMP, lastAccessedBy = ? WHERE projectID = ?")
            ->execute([$_SESSION['userID'], $projectID]);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Project - DepEd BAC Tracking System</title>
    <link rel="stylesheet" href="assets/css/home.css">
    <style>
        /* Project Header Styling */
        .project-header {
            margin-bottom: 20px;
            border: 1px solid #ccc;
            padding: 10px;
            border-radius: 8px;
            background: #f9f9f9;
        }
        .project-header label {
            display: block;
            margin-top: 10px;
            font-weight: bold;
        }
        .project-header input, .project-header textarea {
            width: 100%;
            padding: 3px;
            margin-top: 10px;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        /* Read-only field styling */
        .readonly-field {
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #eee;
            background: #f1f1f1;
            border-radius: 4px; /* Added for consistency */
        }
        /* Back Button */
        .back-btn {
            display: inline-block;
            background-color: #0d47a1;
            color: white;
            padding: 10px 20px;
            margin-bottom: 20px;
            text-decoration: none;
            border-radius: 4px;
            font-weight: bold;
        }
        /* Stages Table Styling */
        table#stagesTable {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            table-layout: fixed; /* Ensures columns respect defined widths */
        }
        table#stagesTable th, table#stagesTable td {
            padding: 10px;
            border: 1px solid #ddd;
            text-align: center;
            vertical-align: middle; /* Align content vertically */
            word-wrap: break-word; /* Allow long words to break */
        }
        table#stagesTable th {
            background-color: #c62828;
            color: white;
        }
        table#stagesTable td input[type="datetime-local"],
        table#stagesTable td input[type="text"] {
            width: calc(100% - 10px); /* Adjust for padding/border */
            padding: 4px;
            box-sizing: border-box;
            border: 1px solid #ccc;
            border-radius: 4px;
        }
        table#stagesTable td input[disabled] {
            background-color: #e9e9e9;
            cursor: not-allowed;
        }
        /* Form for each stage row */
        form.stage-form {
            display: contents; /* Allows row elements to behave as direct table children */
        }
        /* Submit/Unsubmit button styling */
        .submit-stage-btn {
            padding: 8px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            background-color: #28a745; /* Green for submit */
            color: white;
        }
        .submit-stage-btn[disabled] {
            background-color: #cccccc;
            cursor: not-allowed;
        }
        .submit-stage-btn.unsubmit-btn {
            background-color: #dc3545; /* Red for unsubmit */
        }

/* === Default Desktop Styling === */
.card-view {
    display: none;
}
.table-wrapper {
    display: block;
}

/* === Mobile View: When screen is < 500px === */
@media screen and (max-width: 499px) {
    .table-wrapper {
        display: none;
    }
    .card-view {
        display: block;
    }
    .dashboard-container {
        width: 100%;
        padding: 10px 15px;
        margin: 0 auto;

    }
    .dashboard-container input,
    .dashboard-container textarea {
        font-size: 16px;
    }
    .dashboard-container h2,
    .dashboard-container h3 {
        font-size: 20px;
    }
    .submit-stage-btn {
        font-size: 16px;
        padding: 10px;
    }
}
        /* === Enhanced Card View Styling: When screen is ≥ 500px === */
/* === Default: Desktop View (≥ 500px) === */
.table-wrapper {
    display: block;
}
.card-view {
    display: none;
}

/* === Mobile View (< 500px): Switch to Card View === */
@media screen and (max-width: 499px) {
    .table-wrapper {
        display: none;
    }

    .card-view {
        display: block;
        width: auto;
        max-width: 400px;
        margin: 0 auto;
    }

    .dashboard-container {
        width: 80%;
        height: auto;
        padding: 5px 15px;
        margin-top: 10vh;
    }

    .dashboard-container input,
    .dashboard-container textarea {
        font-size: 16px;
    }

    .dashboard-container h2,
    .dashboard-container h3 {
        font-size: 20px;
    }

    .submit-stage-btn {
        width: 50%;
        display: block;
        margin: 10px auto 0 auto;
    }
}
        /* === Card Styling === */
        .stage-card {
            border: 1px solid #ccc;
            border-radius: 8px;
            margin: 10px 0;
            padding: 15px;
            background: #f9f9f9;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.05);
        }
        .stage-card label {
            font-weight: bold;
            display: block;
            margin-top: 10px;
        }
        .stage-card input[type="text"],
        .stage-card input[type="datetime-local"] {
            width: 100%;
            padding: 6px;
            margin-top: 4px;
            border-radius: 4px;
            border: 1px solid #ccc;
            box-sizing: border-box;
        }
        .stage-card .submit-stage-btn {
            margin-top: 10px;
            width: 100%;
        }
        .project-status {
            text-align: right;
            font-size: 1.2em;
            font-weight: bold;
            margin-bottom: 15px;
            padding: 8px;
            border-radius: 5px;
            color: white;
        }
        .project-status.finished {
            background-color: #28a745; /* Green */
        }
        .project-status.in-progress {
            background-color: #ffc107; /* Orange/Yellow */
            color: #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <a href="index.php" style="display: flex; align-items: center; text-decoration: none; color: inherit;">
            <img src="assets/images/DEPED-LAOAG_SEAL_Glow.png" alt="DepEd Logo" class="header-logo">
            <div class="header-text">
                <div class="title-left">
                    SCHOOLS DIVISION OF LAOAG CITY<br>DEPARTMENT OF EDUCATION
                </div>
                <?php if (isset($showTitleRight) && $showTitleRight): ?>
                <div class="title-right">
                    Bids and Awards Committee Tracking System
                </div>
                <?php endif; ?>
            </div>
        </a>
        <div class="user-menu">
            <span class="user-name"><?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <div class="dropdown">
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon">
                <div class="dropdown-content">
                    <a href="logout.php" id="logoutBtn">Log out</a>
                </div>
            </div>
        </div>
    </div>

    <div class="dashboard-container">
        <a href="index.php" class="back-btn">&larr; Back to Dashboard</a>

        <h2>Edit Project</h2>

        <?php
            if (isset($errorHeader)) { echo "<p style='color:red;'>$errorHeader</p>"; }
            if (isset($successHeader)) { echo "<p style='color:green;'>$successHeader</p>"; }
            if (isset($stageError)) { echo "<p style='color:red;'>$stageError</p>"; }
        ?>

        <div class="project-header">
            <label for="prNumber">PR Number:</label>
            <?php if ($_SESSION['admin'] == 1): ?>
            <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" style="margin-bottom:10px;">
                <input type="text" name="prNumber" id="prNumber" value="<?php echo htmlspecialchars($project['prNumber']); ?>" required>
            <?php else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['prNumber']); ?></div>
            <?php endif; ?>

            <label for="projectDetails">Project Details:</label>
            <?php if ($_SESSION['admin'] == 1): ?>
                <textarea name="projectDetails" id="projectDetails" required><?php echo htmlspecialchars($project['projectDetails']); ?></textarea>
            <? else: ?>
                <div class="readonly-field"><?php echo htmlspecialchars($project['projectDetails']); ?></div>
            <?php endif; ?>

            <label>User Info:</label>
            <p><?php echo htmlspecialchars($project['firstname'] . " " . $project['lastname'] . " | Office: " . ($project['officename'] ?? 'N/A')); ?></p>

            <label>Date Created:</label>
            <p><?php echo date("m-d-Y h:i A", strtotime($project['createdAt'])); ?></p>

            <label>Date Last Edited:</label>
            <?php
            $lastEdited = "Not Available";
            if ($project['lastAccessedAt'] && $project['lastAccessedBy']) {
                $stmtUser = $pdo->prepare("SELECT firstname, lastname FROM tbluser WHERE userID = ?");
                $stmtUser->execute([$project['lastAccessedBy']]);
                $lastUser = $stmtUser->fetch();
                if ($lastUser) {
                    $lastEdited = htmlspecialchars($lastUser['firstname'] . " " . $lastUser['lastname']) . ", accessed on " . date("m-d-Y h:i A", strtotime($project['lastAccessedAt']));
                }
            }
            ?>
            <p><?php echo htmlspecialchars($lastEdited); ?></p>
            <?php if ($_SESSION['admin'] == 1): ?>
                <button type="submit" name="update_project_header">Update Project Details</button>
            </form>
            <?php endif; ?>
        </div>

        <h3>Project Stages</h3>
        <?php
            // Display Project Status
            $projectStatusClass = $noticeToProceedSubmitted ? 'finished' : 'in-progress';
            $projectStatusText = $noticeToProceedSubmitted ? 'Status: Finished' : 'Status: In Progress';
            echo '<div class="project-status ' . $projectStatusClass . '">' . $projectStatusText . '</div>';
        ?>
        <?php if (isset($stageSuccess)) { echo "<p style='color:green;'>$stageSuccess</p>"; } ?>
        <div class="table-wrapper">
            <table id="stagesTable">
                <thead>
                    <tr>
                        <th style="width: 15%;">Stage</th>
                        <th style="width: 20%;">Created</th>
                        <th style="width: 20%;">Approved</th>
                        <th style="width: 15%;">Office</th>
                        <th style="width: 15%;">Remark</th>
                        <th style="width: 15%;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    foreach ($stagesOrder as $index => $stage):
                        $safeStage = str_replace(' ', '_', $stage);
                        $currentStageData = $stagesMap[$stage] ?? null;

                        $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

                        $value_created = ($currentStageData && !empty($currentStageData['createdAt']))
                                                 ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
                        $value_approved = ($currentStageData && !empty($currentStageData['approvedAt']))
                                                  ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
                        $value_office = ($currentStageData && !empty($currentStageData['office']))
                                                ? htmlspecialchars($currentStageData['office']) : "";
                        $value_remark = ($currentStageData && !empty($currentStageData['remarks']))
                                                ? htmlspecialchars($currentStageData['remarks']) : "";

                        // Determine if this stage is the "last processed" one (only relevant for Unsubmit button for admin)
                        $isLastProcessedStage = ($stage === $lastSubmittedStageName);

                        // A stage can be submitted by a non-admin user if:
                        // 1. It's the first stage (Purchase Request) OR
                        // 2. The previous stage is submitted.
                        $prevStageSubmitted = false;
                        if ($index > 0) {
                            $prevStage = $stagesOrder[$index - 1];
                            if (isset($stagesMap[$prevStage]) && $stagesMap[$prevStage]['isSubmitted'] == 1) {
                                $prevStageSubmitted = true;
                            }
                        }
                        $allowSubmissionToUser = ($index == 0 || $prevStageSubmitted);


                        // === NEW DISABLING LOGIC FOR ALL FIELDS ===
                        $disableFields = true; // Start by assuming fields are disabled for *all* stages

                        // Only ENABLE fields if it's the current active stage ready for submission (not submitted, but its predecessor is or it's the first)
                        if (!$currentSubmitted && $allowSubmissionToUser) {
                            $disableFields = false;
                        }

                        // Special handling for the 'Created' field:
                        $disableCreatedField = true; // Default 'Created' field to disabled
                        if ($_SESSION['admin'] == 1) { // Only admins can potentially edit 'Created'
                            // Admin can edit 'Created' only if it's the current active stage to be submitted,
                            // AND IT'S NOT the 'Purchase Request' stage.
                            if (!$currentSubmitted && $allowSubmissionToUser && $stage !== 'Purchase Request') {
                                $disableCreatedField = false;
                            }
                        }

                    ?>
                    <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
                        <tr data-stage="<?php echo htmlspecialchars($stage); ?>">
                            <td><?php echo htmlspecialchars($stage); ?></td>
                            <td>
                                <input type="datetime-local" name="created_<?php echo $safeStage; ?>"
                                        value="<?php echo $value_created; ?>"
                                        <?php if ($disableCreatedField) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="datetime-local" name="approvedAt"
                                        value="<?php echo $value_approved; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="text" name="office_<?php echo $safeStage; ?>"
                                        value="<?php echo $value_office; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="text" name="remark"
                                        value="<?php echo $value_remark; ?>"
                                        <?php if ($disableFields) echo "disabled"; ?>>
                            </td>
                            <td>
                                <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
                                <?php
                                    if ($currentSubmitted) {
                                        // Stage is submitted
                                        if ($_SESSION['admin'] == 1 && $isLastProcessedStage) {
                                            // Admin and this is the last submitted stage: show Unsubmit
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                                        } else {
                                            // Either not admin, or admin but not last submitted stage: show Finished (disabled)
                                            echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                                        }
                                    } else {
                                        // Stage is not submitted
                                        if ($allowSubmissionToUser) {
                                            // Allowed to submit (previous stage submitted or it's the first)
                                            echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                                        } else {
                                            // Not allowed to submit yet
                                            echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                                        }
                                    }
                                ?>
                            </td>
                        </tr>
                    </form>
                    <?php endforeach; ?>
                </tbody>
            </table>
    </div>

    <div class="card-view">
    <?php foreach ($stagesOrder as $index => $stage):
        $safeStage = str_replace(' ', '_', $stage);
        $currentStageData = $stagesMap[$stage] ?? null;
        $currentSubmitted = ($currentStageData && $currentStageData['isSubmitted'] == 1);

        $value_created = ($currentStageData && !empty($currentStageData['createdAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['createdAt'])) : "";
        $value_approved = ($currentStageData && !empty($currentStageData['approvedAt'])) ? date("Y-m-d\TH:i", strtotime($currentStageData['approvedAt'])) : "";
        $value_office = ($currentStageData && !empty($currentStageData['office'])) ? htmlspecialchars($currentStageData['office']) : "";
        $value_remark = ($currentStageData && !empty($currentStageData['remarks'])) ? htmlspecialchars($currentStageData['remarks']) : "";

        // Determine if this stage is the "last processed" one (only relevant for Unsubmit button for admin)
        $isLastProcessedStage = ($stage === $lastSubmittedStageName);

        $prevStageSubmitted = false;
        if ($index > 0) {
            $prevStage = $stagesOrder[$index - 1];
            if (isset($stagesMap[$prevStage]) && $stagesMap[$prevStage]['isSubmitted'] == 1) {
                $prevStageSubmitted = true;
            }
        }
        $allowSubmissionToUser = ($index == 0 || $prevStageSubmitted);

        // === NEW DISABLING LOGIC FOR ALL FIELDS (for card view) ===
        $disableFields = true;

        if (!$currentSubmitted && $allowSubmissionToUser) {
            $disableFields = false;
        }

        // Special handling for the 'Created' field for card view:
        $disableCreatedField = true;
        if ($_SESSION['admin'] == 1) {
            // Admin can edit 'Created' only if it's the current active stage to be submitted,
            // AND IT'S NOT the 'Purchase Request' stage.
            if (!$currentSubmitted && $allowSubmissionToUser && $stage !== 'Purchase Request') {
                $disableCreatedField = false;
            }
        }
    ?>
    <form action="edit_project.php?projectID=<?php echo $projectID; ?>" method="post" class="stage-form">
        <div class="stage-card">
            <h4><?php echo htmlspecialchars($stage); ?></h4>

            <label>Created At:</label>
            <input type="datetime-local" name="created_<?php echo $safeStage; ?>" value="<?php echo $value_created; ?>" <?php if ($disableCreatedField) echo "disabled"; ?>>

            <label>Approved At:</label>
            <input type="datetime-local" name="approvedAt" value="<?php echo $value_approved; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <label>Office:</label>
            <input type="text" name="office_<?php echo $safeStage; ?>" value="<?php echo $value_office; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <label>Remark:</label>
            <input type="text" name="remark" value="<?php echo $value_remark; ?>" <?php if ($disableFields) echo "disabled"; ?>>

            <input type="hidden" name="stageName" value="<?php echo htmlspecialchars($stage); ?>">
            <div style="margin-top:10px;">
                <?php
                if ($currentSubmitted) {
                    // Stage is submitted
                    if ($_SESSION['admin'] == 1 && $isLastProcessedStage) {
                        // Admin and this is the last submitted stage: show Unsubmit
                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn unsubmit-btn">Unsubmit</button>';
                    } else {
                        // Either not admin, or admin but not last submitted stage: show Finished (disabled)
                        echo '<button type="button" class="submit-stage-btn" disabled>Finished</button>';
                    }
                } else {
                    // Stage is not submitted
                    if ($allowSubmissionToUser) {
                        // Allowed to submit (previous stage submitted or it's the first)
                        echo '<button type="submit" name="submit_stage" class="submit-stage-btn">Submit</button>';
                    } else {
                        // Not allowed to submit yet
                        echo '<button type="button" class="submit-stage-btn" disabled>Pending</button>';
                    }
                }
                ?>
            </div>
        </div>
    </form>
    <?php endforeach; ?>
</div>

</body>
</html>