<?php
session_start();
require 'config.php'; // Ensure your PDO connection is set up correctly

// Redirect if user is not logged in.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Define the ordered list of stages and their short forms for status display.
$stagesOrder = [
    'Purchase Request'      => 'PR',
    'RFQ 1'                 => 'RFQ1',
    'RFQ 2'                 => 'RFQ2',
    'RFQ 3'                 => 'RFQ3',
    'Abstract of Quotation' => 'AoQ',
    'Purchase Order'        => 'PO',
    'Notice of Award'       => 'NoA',
    'Notice to Proceed'     => 'NtP'
];

/* ---------------------------
   Project Deletion (Admin Only)
------------------------------ */
if (isset($_GET['deleteProject']) && $_SESSION['admin'] == 1) {
    $delID = intval($_GET['deleteProject']);
    try {
        $pdo->beginTransaction();
        // Delete associated stages first
        $stmtDelStages = $pdo->prepare("DELETE FROM tblproject_stages WHERE projectID = ?");
        $stmtDelStages->execute([$delID]);
        // Then delete the project itself
        $stmtDel = $pdo->prepare("DELETE FROM tblproject WHERE projectID = ?");
        $stmtDel->execute([$delID]);
        $pdo->commit();
        header("Location: index.php");
        exit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        $deleteProjectError = "Error deleting project: " . $e->getMessage();
    }
}

/* ---------------------------
   Add Project Processing
------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['addProject'])) {
    $prNumber = trim($_POST['prNumber']);
    $projectDetails = trim($_POST['projectDetails']);
    $userID = $_SESSION['userID'];
    if (empty($prNumber) || empty($projectDetails)) {
        $projectError = "Please fill in all required fields.";
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO tblproject (prNumber, projectDetails, userID) VALUES (?, ?, ?)");
            $stmt->execute([$prNumber, $projectDetails, $userID]);
            $newProjectID = $pdo->lastInsertId();
            // Insert stages for the new project (set createdAt for 'Purchase Request')
            foreach ($stagesOrder as $stageName => $shortForm) {
                $insertCreatedAt = ($stageName === 'Purchase Request') ? date("Y-m-d H:i:s") : null;
                $stmtInsertStage = $pdo->prepare("INSERT INTO tblproject_stages (projectID, stageName, createdAt) VALUES (?, ?, ?)");
                $stmtInsertStage->execute([$newProjectID, $stageName, $insertCreatedAt]);
            }
            $pdo->commit();
            header("Location: index.php");
            exit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            $projectError = "Error adding project: " . $e->getMessage();
        }
    }
}

/* ---------------------------
   Retrieve Projects (with optional search)
------------------------------ */
$search = "";
if (isset($_GET['search'])) {
    $search = trim($_GET['search']);
}

// Modified SQL query to fetch additional stage information.
$sql = "SELECT p.*, u.firstname, u.lastname,
        (SELECT isSubmitted FROM tblproject_stages WHERE projectID = p.projectID AND stageName = 'Notice to Proceed') AS notice_to_proceed_submitted,
        (SELECT s.stageName FROM tblproject_stages s WHERE s.projectID = p.projectID AND s.isSubmitted = 0
            ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
            LIMIT 1) AS first_unsubmitted_stage
        FROM tblproject p
        JOIN tbluser u ON p.userID = u.userID";

if ($search !== "") {
    $sql .= " WHERE p.projectDetails LIKE ? OR p.prNumber LIKE ?";
}
$sql .= " ORDER BY COALESCE(p.editedAt, p.createdAt) DESC";
$stmt = $pdo->prepare($sql);
if ($search !== "") {
    $stmt->execute(["%$search%", "%$search%"]);
} else {
    $stmt->execute();
}
$projects = $stmt->fetchAll();

/* ---------------------------
   Calculate Statistics
------------------------------ */
$totalProjects = count($projects);
$finishedProjects = 0;

foreach ($projects as $project) {
    if ($project['notice_to_proceed_submitted'] == 1) {
        $finishedProjects++;
    }
}

$ongoingProjects = $totalProjects - $finishedProjects;
$percentageDone = ($totalProjects > 0) ? round(($finishedProjects / $totalProjects) * 100, 2) : 0;
$percentageOngoing = ($totalProjects > 0) ? round(($ongoingProjects / $totalProjects) * 100, 2) : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Dashboard-DepEd BAC Tracking System</title>
  <link rel="stylesheet" href="assets/css/home.css">
  <link rel="stylesheet" href="https://www.w3schools.com/w3css/5/w3.css">
  <style>
    /* Modal styling for popups */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.4);
    }
    .modal-content {
        background-color: #fefefe;
        margin: 10% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 90%;
        max-width: 500px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    }
    .modal-content.stats-modal {
        max-width: 1100px;
        width: 90%;
    }
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    .close:hover { color: black; }
    form label {
        display: block;
        margin-top: 10px;
    }
    form input, form textarea {
        width: 100%;
        padding: 8px;
        margin-top: 4px;
        box-sizing: border-box;
    }
    form button {
        margin-top: 15px;
        padding: 10px;
        width: 100%;
        border: none;
        background-color: #0d47a1;
        color: white;
        font-weight: bold;
        border-radius: 4px;
        cursor: pointer;
    }

    /* Statistics Modal Styling */
    .stats-container {
        text-align: center;
        padding: 20px 0;
    }
    .stats-container h2 {
        color: #333;
        margin-bottom: 30px;
        font-size: 1.8em;
    }
    .stats-grid {
        display: flex;
        justify-content: space-around;
        flex-wrap: wrap;
        gap: 20px;
        margin-top: 30px;
    }
    .stat-item {
        background-color: #f8f9fa;
        padding: 25px 20px;
        border-radius: 12px;
        flex: 1;
        min-width: 180px;
        max-width: 30%;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        border: 2px solid #e9ecef;
        transition: transform 0.2s ease, box-shadow 0.2s ease;
    }
    .stat-item:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
    }
    .stat-item h3 {
        margin: 0 0 15px 0;
        color: #555;
        font-size: 1.1em;
        font-weight: 600;
    }
    .stat-value {
        font-size: 2.8em;
        font-weight: bold;
        color: #0d47a1;
        margin-bottom: 5px;
    }
    .stat-value.done {
        color: #28a745;
    }
    .stat-value.ongoing {
        color: #ffc107;
    }
    .stat-percentage {
        font-size: 1em;
        color: #6c757d;
        font-weight: 500;
    }

    /* Table styling */
    .dashboard-table {
        width: 100%;
        border-collapse: collapse;
    }
    .dashboard-table thead tr {
        background-color: #c62828;
        color: white;
        text-align: center;
    }
    .dashboard-table th,
    .dashboard-table td {
        padding: 12px 20px;
        border: 1px solid #eee;
    }
    .dashboard-table td {
        text-align: center;
        vertical-align: middle;
    }

    /* Action Button Styles */
    .edit-project-btn, .delete-btn {
        width: 30px;
        height: 30px;
        display: inline-flex;
        justify-content: center;
        align-items: center;
        padding: 0;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        font-size: 16px;
        text-decoration: none;
        color: inherit;
        background-color: transparent;
    }
    .edit-project-btn { background-color: #0D47A1; color: white; }
    .delete-btn { background-color: #C62828; color: white; }
    .back-btn {
        display: inline-block;
        background-color: #0d47a1;
        color: #fff;
        padding: 8px 12px;
        text-decoration: none;
        border-radius: 4px;
        margin: 10px;
    }

    /* Responsive styling */
    @media (max-width: 900px) {
        .dashboard-table {
            display: block;
            overflow-x: auto;
            white-space: nowrap;
        }
        .stats-grid {
            flex-direction: column;
            align-items: center;
        }
        .stat-item {
            max-width: 90%;
            width: 100%;
        }
    }
    /* Scrollbar styling for the table */
    .dashboard-table::-webkit-scrollbar {
        height: 8px;
    }
    .dashboard-table::-webkit-scrollbar-thumb {
        background: #c62828;
        border-radius: 4px;
    }

    /* Header styling */
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
    }
    .header a {
        display: flex;
        align-items: center;
        text-decoration: none;
        color: inherit;
    }
    .header-logo {
        width: 50px;
        height: auto;
    }
    .header-text {
        margin-left: 10px;
    }
    .title-left, .title-right {
        font-size: 14px;
        line-height: 1.2;
    }

    /* User-menu dropdown */
    .user-menu {
        display: flex;
        align-items: center;
        position: relative;
    }
    .user-name {
        margin-right: 10px;
    }
    .user-icon {
        width: 32px;
        height: 32px;
        border-radius: 50%;
        cursor: pointer;
    }
    .dropdown-content {
        display: none;
        position: absolute;
        right: 0;
        background-color: #fff;
        box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        border-radius: 6px;
        z-index: 100;
    }
    .dropdown-content a {
        flex-direction: row;
        display: block;
        padding: 8px 20px;
        text-decoration: none;
        color: #333;
        white-space: nowrap;
    }
    .dropdown.open .dropdown-content {
        display: block;
    }

    /* Top Bar Container: Three-column layout */
    .table-top-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 150px;
        padding: 0 10px;
        gap: 20px;
    }

    /* Left column - Add Project button */
    .left-controls {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
    }

    /* Center column - Search bar */
    .center-search {
        flex: 1;
        display: flex;
        justify-content: center;
        max-width: 400px;
        margin: 0 auto;
    }
    .dashboard-search-bar {
        width: 100%;
        padding: 8px;
        border: 1px solid #ccc;
        border-radius: 4px;
    }

    /* Right column - View Statistics button */
    .right-controls {
        flex: 0 0 auto;
        display: flex;
        align-items: center;
    }
    .add-pr-button, .view-stats-button {
        background-color: #0d47a1;
        color: #fff;
        padding: 8px 12px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        display: flex;
        align-items: center;
        text-decoration: none;
        margin-left: 35px;
        margin-right: 35px;
    }
    .add-pr-button img, .view-stats-button img {
        vertical-align: middle;
        margin-right: 5px;
    }
    .add-pr-button:hover, .view-stats-button:hover {
        background-color: #1565c0;
    }

    /* "No results" message */
    #noResults {
        text-align: center;
        font-weight: bold;
        margin-top: 20px;
    }

    /* Responsive design for smaller screens */
    @media (max-width: 768px) {
        .table-top-bar {
            flex-direction: column;
            gap: 15px;
            margin-top: 120px;
        }
        
        .left-controls, .right-controls {
            order: 2;
        }
        
        .center-search {
            order: 1;
            max-width: 100%;
            width: 100%;
        }
        
        .dashboard-search-bar {
            width: 100%;
        }
    }
    @media (max-width: 480px) {
        .table-top-bar {
            padding: 0 5px;
        }
        
        .add-pr-button, .view-stats-button {
            padding: 6px 10x;
            font-size: 14px;
        }
    }
    @media (max-width: 500px) {
        .dashboard-table thead {
            display: none;
        }
        .dashboard-table,
        .dashboard-table tbody,
        .dashboard-table tr,
        .dashboard-table td {
            display: block;
            width: 100%;
        }
        .dashboard-table tr {
            margin-bottom: 15px;
            border: 1px solid #ddd;
            border-radius: 8px;
            background-color: #fff;
            padding: 0;
            box-shadow: 0 2px 6px rgba(0,0,0,0.05);
        }
        .dashboard-table td {
            text-align: left;
            padding: 10px 12px;
            border: none;
            border-bottom: 1px solid #eee;
            position: relative;
            background-color: #fff;
            color: #000;
        }
        .dashboard-table td::before {
            content: attr(data-label);
            font-weight: bold;
            display: block;
            margin-bottom: 4px;
            font-size: 14px;
            color: #444;
        }
        .dashboard-table td:last-child {
            border-bottom: none;
        }
        /* Entire PR Number cell gets red background and white text */
        .dashboard-table .pr-number-cell {
            background-color: #c62828 !important;
            color: white !important;
            border-top-left-radius: 6px;
            border-top-right-radius: 6px;
        }
        .dashboard-table .pr-number-cell::before {
            color: white !important;
        }
    }
  </style>
</head>
<body>
    <div class="header">
        <a href="index.php">
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
            <div class="dropdown" id="profileDropdown">
                <img src="https://cdn-icons-png.flaticon.com/512/149/149071.png" alt="User Icon" class="user-icon" id="profileIcon">
                <span id="dropdownArrow" style="cursor:pointer; margin-left:4px;"></span>
                <div class="dropdown-content">
                    <?php if ($_SESSION['admin'] == 1): ?>
                        <a href="create_account.php">Create Account</a>
                        <a href="manage_accounts.php">Manage Accounts</a>
                    <?php else: ?>
                        <a href="edit_account.php">Change Password</a>
                    <?php endif; ?>
                    <a href="logout.php" id="logoutBtn">Log out</a>
                </div>
            </div>
        </div>
    </div>

    <div id="statsModal" class="modal">
        <div class="modal-content stats-modal">
            <span class="close" id="statsClose">&times;</span>
            <div id="statsModalContentPlaceholder">
                <p style="text-align: center; margin-top: 20px;">Loading statistics...</p>
            </div>
        </div>
    </div>

    <div id="addProjectModal" class="modal">
        <div class="modal-content">
            <span class="close" id="addProjectClose">&times;</span>
            <h2>Add Project</h2>
            <form id="addProjectForm" action="index.php" method="post">
                <label for="prNumber">Project Number (PR Number)*</label>
                <input type="text" name="prNumber" id="prNumber" required>
                <label for="projectDetails">Project Details*</label>
                <textarea name="projectDetails" id="projectDetails" rows="4" required></textarea>
                <button type="submit" name="addProject">Add Project</button>
            </form>
        </div>
    </div>


    <div class="table-top-bar">
        <div class="left-controls">
            <button class="add-pr-button" id="showAddProjectForm">
                <img src="assets/images/Add_Button.png" alt="Add" class="add-pr-icon">
                Add Project
            </button>
        </div>
        <div class="center-search">
            <input type="text" id="searchInput" class="dashboard-search-bar" placeholder="Search by PR Number or Project Details...">
        </div>
        <div class="right-controls">
            <button class="view-stats-button" onclick="loadAndShowStatistics()">
                <img src="assets/images/stats_icon.png" alt="Stats" style="width:24px;height:24px;">
                View Statistics
            </button>
        </div>
    </div>

    <?php
        if (isset($projectError)) {
            echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($projectError) . "</p>";
        }
        if (isset($deleteProjectError)) {
            echo "<p style='color:red; text-align:center;'>" . htmlspecialchars($deleteProjectError) . "</p>";
        }
    ?>

    <div class="container" style="padding: 0 2.5vw;">
        <table class="w3-table-all w3-hoverable dashboard-table">
            <thead>
                <tr class="w3-red">
                    <th style="width:100px;">PR Number</th>
                    <th style="width:100px;">Project Details</th>
                    <th style="width:100px;">Created By</th>
                    <th style="width:120px;">Date Created</th>
                    <th style="width:120px;">Date Edited</th>
                    <th style="width:100px;">Status</th>
                    <th style="width:120px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($projects as $project): ?>
                <tr>
                    <td data-label="PR Number" class="pr-number-cell">
                        <?php echo htmlspecialchars($project['prNumber']); ?>
                    </td>
                    <td data-label="Project Details"><?php echo htmlspecialchars($project['projectDetails']); ?></td>
                    <td data-label="Created By">
                        <?php
                            if (!empty($project['firstname']) && !empty($project['lastname'])) {
                                echo htmlspecialchars(substr($project['firstname'], 0, 1) . ". " . $project['lastname']);
                            } else {
                                echo "N/A";
                            }
                        ?>
                    </td>
                    <td data-label="Date Created"><?php echo date("m-d-Y", strtotime($project['createdAt'])); ?></td>
                    <td data-label="Date Edited"><?php echo date("m-d-Y", strtotime($project['editedAt'])); ?></td>
                    <td data-label="Status">
                        <?php
                            if ($project['notice_to_proceed_submitted'] == 1) {
                                echo 'Finished';
                            } else {
                                echo htmlspecialchars($project['first_unsubmitted_stage'] ?? 'No Stages Started');
                            }
                        ?>
                    </td>
                    <td data-label="Actions">
                        <a href="edit_project.php?projectID=<?php echo $project['projectID']; ?>" class="edit-project-btn" title="Edit Project" style="margin-right: 5px;">
                            <img src="assets/images/Edit_icon.png" alt="Edit Project" style="width:24px;height:24px;">
                        </a>
                        <?php if ($_SESSION['admin'] == 1): ?>
                        <a href="index.php?deleteProject=<?php echo $project['projectID']; ?>" class="delete-btn" onclick="return confirm('Are you sure you want to delete this project and all its stages?');" title="Delete Project">
                            <img src="assets/images/delete_icon.png" alt="Delete Project" style="width:24px;height:24px;">
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div id="noResults" style="display:none;">No results</div>

    <script>
        // Define modal elements globally at the very top of your script
        // This is CRITICAL for them to be recognized by subsequent functions/listeners.
        const addProjectModal = document.getElementById('addProjectModal');
        const statsModal = document.getElementById('statsModal');
        const statsModalContentPlaceholder = document.getElementById('statsModalContentPlaceholder');
        const statsClose = document.getElementById('statsClose'); // The 'X' button to close stats modal
        const addProjectClose = document.getElementById('addProjectClose'); // The 'X' button to close add project modal

        // --- Modal Closing Logic (Escape Key) ---
        document.addEventListener('keydown', function(event) {
            if (event.key === "Escape") {
                // Check if the element exists and is displayed before trying to hide it
                if (addProjectModal && addProjectModal.style.display === 'block') {
                    addProjectModal.style.display = 'none';
                }
                if (statsModal && statsModal.style.display === 'block') {
                    statsModal.style.display = 'none';
                    // Clear content when closed by Escape
                    if (statsModalContentPlaceholder) {
                        statsModalContentPlaceholder.innerHTML = '';
                    }
                }
            }
        });

        // --- Search functionality for filtering projects. ---
        document.getElementById("searchInput").addEventListener("keyup", function() {
            let query = this.value.toLowerCase().trim();
            let rows = document.querySelectorAll("table.dashboard-table tbody tr");
            let visibleCount = 0;
            const displayStyle = window.matchMedia("(max-width: 500px)").matches ? "block" : "table-row";
            rows.forEach(row => {
                let prNumber = row.children[0].textContent.toLowerCase();
                let projectDetails = row.children[1].textContent.toLowerCase();
                if (prNumber.includes(query) || projectDetails.includes(query)) {
                    row.style.display = displayStyle;
                    visibleCount++;
                } else {
                    row.style.display = "none";
                }
            });
            const noResultsDiv = document.getElementById("noResults");
            noResultsDiv.style.display = (visibleCount === 0) ? "block" : "none";
        });

        // --- Profile dropdown toggle logic ---
        const profileIcon = document.getElementById('profileIcon');
        const dropdownArrow = document.getElementById('dropdownArrow');
        const profileDropdown = document.getElementById('profileDropdown');

        function toggleDropdown(event) {
            event.stopPropagation();
            profileDropdown.classList.toggle('open');
        }
        profileIcon.addEventListener('click', toggleDropdown);
        dropdownArrow.addEventListener('click', toggleDropdown);

        document.addEventListener('click', function(event) {
            if (!profileDropdown.contains(event.target)) {
                profileDropdown.classList.remove('open');
            }
            // Close Add Project Modal if click outside
            if (addProjectModal && event.target === addProjectModal) {
                addProjectModal.style.display = 'none';
            }
            // Close Stats Modal if click outside
            if (statsModal && event.target === statsModal) {
                statsModal.style.display = 'none';
                // Clear content when closed by outside click
                if (statsModalContentPlaceholder) {
                    statsModalContentPlaceholder.innerHTML = '';
                }
            }
        });

        // --- Add Project Modal logic ---
        // Assuming you have a button with id="showAddProjectForm" to open this modal
        const showAddProjectFormButton = document.getElementById('showAddProjectForm');
        if (showAddProjectFormButton) { // Check if the button exists
            showAddProjectFormButton.addEventListener('click', function() {
                if (addProjectModal) { // Check if modal element exists
                    addProjectModal.style.display = 'block';
                }
            });
        }
        if (addProjectClose) { // Check if the close button exists
            addProjectClose.addEventListener('click', function() {
                if (addProjectModal) { // Check if modal element exists
                    addProjectModal.style.display = 'none';
                }
            });
        }


        // --- Statistics Modal (New dynamic loading function) ---
        // This function will be called directly by the button's onclick="loadAndShowStatistics()"
        function loadAndShowStatistics() {
            // Display a loading message immediately
            if (statsModalContentPlaceholder) {
                statsModalContentPlaceholder.innerHTML = '<p style="text-align: center; margin-top: 20px;">Loading statistics...</p>';
            }
            if (statsModal) {
                statsModal.style.display = 'block'; // Show the modal frame immediately
            }

            fetch('statistics.php')
                .then(response => {
                    if (!response.ok) {
                        // Log the status and text of the bad response
                        console.error('Network response was not ok:', response.status, response.statusText);
                        // Try to get more details if it's an HTTP error (e.g., 404, 500)
                        return response.text().then(text => {
                            throw new Error('HTTP error! Status: ' + response.status + ' - ' + text);
                        });
                    }
                    return response.text();
                })
                .then(html => {
                    if (statsModalContentPlaceholder) {
                        statsModalContentPlaceholder.innerHTML = html; // Insert the fetched HTML and CSS
                    }
                })
                .catch(error => {
                    console.error('There has been a problem with your fetch operation:', error);
                    if (statsModalContentPlaceholder) {
                        statsModalContentPlaceholder.innerHTML = '<p style="color: red; text-align: center; margin-top: 20px;">Failed to load statistics. Please try again.<br>Error: ' + error.message + '</p>';
                    }
                    if (statsModal) {
                        statsModal.style.display = 'block'; // Ensure modal is visible to show error
                    }
                });
        }

        // --- Close Statistics Modal (X button) ---
        if (statsClose) { // Check if the close button exists
            statsClose.addEventListener('click', function() {
                if (statsModal) { // Check if modal element exists
                    statsModal.style.display = 'none';
                }
                if (statsModalContentPlaceholder) { // Clear content when closed by X button
                    statsModalContentPlaceholder.innerHTML = '';
                }
            });
        }

        // NOTE: Your 'edit_project_btn', 'view-timeline-btn', 'delete-btn'
        // are currently anchor tags (<a>) in your HTML, not buttons (<button>).
        // If you want them to trigger JavaScript for modals, you should change
        // them to <button> tags and remove their 'href' attributes,
        // then add event listeners similar to 'showAddProjectForm'.
        // For now, they will behave as standard links.
    </script>
</body>
</html>