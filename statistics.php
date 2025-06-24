<?php
session_start();
require 'config.php'; // Ensure your PDO connection is set up correctly

// Redirect if user is not logged in.
// IMPORTANT: For this file, if it's strictly loaded via AJAX into a modal,
// you might *not* want a full page redirect here. Instead, you might want
// to return a specific error message or an empty div if the session is not set.
// However, sticking to your original logic for now.
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}

// Define the ordered list of stages for display and processing
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

/* ---------------------------
    Retrieve Projects for Statistics
------------------------------ */
// Fetch all projects along with their 'Notice to Proceed' status
// and their *first* unsubmitted stage. This is crucial for determining current stage.
$sql = "SELECT p.*,
        (SELECT isSubmitted FROM tblproject_stages WHERE projectID = p.projectID AND stageName = 'Notice to Proceed') AS notice_to_proceed_submitted,
        (SELECT s.stageName FROM tblproject_stages s WHERE s.projectID = p.projectID AND s.isSubmitted = 0
            ORDER BY FIELD(s.stageName, 'Purchase Request','RFQ 1','RFQ 2','RFQ 3','Abstract of Quotation','Purchase Order','Notice of Award','Notice to Proceed') ASC
            LIMIT 1) AS first_unsubmitted_stage
        FROM tblproject p";
$stmt = $pdo->prepare($sql);
$stmt->execute();
$projects = $stmt->fetchAll();

// --- Calculate PR Status Counts and Percentages ---
$totalProjects = count($projects);
$finishedProjects = 0;
$ongoingProjects = 0;

// Initialize stage counts
$stageCounts = [];
foreach ($stagesOrder as $stage) {
    $stageCounts[$stage] = 0;
}
$stageCounts['Finished'] = 0;

// This will store the breakdown data for the new nested grid
$ongoingBreakdownData = [];

foreach ($projects as $project) {
    if ($project['notice_to_proceed_submitted'] == 1) {
        $finishedProjects++;
        $stageCounts['Finished']++;
    } else {
        $ongoingProjects++;
        if (!empty($project['first_unsubmitted_stage'])) {
            $stageCounts[$project['first_unsubmitted_stage']]++;
        }
    }
}

// Populate ongoingBreakdownData for the nested grid
foreach ($stagesOrder as $stage) {
    if (!empty($stageCounts[$stage]) && $stageCounts[$stage] > 0) {
        $shortForm = '';
        switch ($stage) {
            case 'Purchase Request': $shortForm = 'PR'; break;
            case 'RFQ 1': $shortForm = 'RFQ1'; break;
            case 'RFQ 2': $shortForm = 'RFQ2'; break;
            case 'RFQ 3': $shortForm = 'RFQ3'; break;
            case 'Abstract of Quotation': $shortForm = 'AoQ'; break;
            case 'Purchase Order': $shortForm = 'PO'; break;
            case 'Notice of Award': $shortForm = 'NoA'; break;
            case 'Notice to Proceed': $shortForm = 'NtP'; break;
            default: $shortForm = $stage; break;
        }
        $ongoingBreakdownData[] = [
            'name' => $shortForm,
            'count' => $stageCounts[$stage]
        ];
    }
}

$percentageDone = ($totalProjects > 0) ? round(($finishedProjects / $totalProjects) * 100, 2) : 0;
$percentageOngoing = ($totalProjects > 0) ? round(($ongoingProjects / $totalProjects) * 100, 2) : 0;

// IMPORTANT: No <html>, <head>, <body> tags here.
// Only the content for the placeholder, and the minimal styles required for that content.
?>
<style>
    /* Ensure these styles are scoped or minimal to avoid conflicts */
    /* Adjustments to `body` properties from original are now on `.stats-modal` or its children */

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
    
    #statsModalContentPlaceholder {
        font-family: Arial, sans-serif;
        /* No background-color here as the modal container already has one */
        padding: 0; /* Remove default padding, container handles it */
        margin: 0;
        width: 100%; /* Ensure it fills its parent container */
        height: 100%; /* Ensure it fills its parent container */
        overflow-y: auto; /* Allow scrolling if content is too long */
    }

    #statsModalContentPlaceholder h2 {
        color: #333;
        margin-bottom: 20px;
        text-align: center; /* Ensure heading is centered within the modal */
    }
    .stats-grid {
        display: flex;
        justify-content: space-between;
        flex-wrap: nowrap; /* Prevent wrapping to force horizontal layout */
        gap: 20px;
        margin-top: 30px;
        overflow-x: auto; /* Enable horizontal scrolling if content overflows */
        padding-bottom: 10px; /* Space for potential scrollbar */
    }
    .stat-item {
        background-color: #e0e0e0;
        padding: 15px; /* Slightly less padding for main items */
        border-radius: 8px;
        flex-shrink: 0;
        min-width: 230px; /* Adjusted width for 4 items + content */
        max-width: 230px; /* Adjusted width for 4 items + content */
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        display: flex;
        flex-direction: column;
        justify-content: center;
        align-items: center;
        min-height: 150px; /* Increased height to accommodate inner grid */
    }
    .stat-item h3 {
        margin: 0 0 10px 0;
        color: #555;
        font-size: 1.2em;
    }
    .stat-value {
        font-size: 2.5em;
        font-weight: bold;
        color: #0d47a1; /* Default blue for total */
    }
    .stat-value.done {
        color: #28a745; /* Green for done */
    }
    .stat-value.ongoing {
        color: #ffc107; /* Orange/Yellow for ongoing */
    }
    .stat-percentage {
        font-size: 1.1em;
        color: #777;
        margin-top: 5px;
    }

    /* Styles for the nested breakdown mini-grid */
    .stat-item.breakdown-container {
        background-color: #ffe0b2; /* Distinct background for the breakdown container */
        border: 1px solid #ffcc80;
        align-items: flex-start; /* Align content to the start */
        padding: 15px; /* Ensure consistent padding */
    }
    .breakdown-container h3 {
        color: #e65100;
        margin-bottom: 10px;
        width: 100%; /* Ensure heading takes full width */
        text-align: center; /* Center the heading */
    }

    .breakdown-mini-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(80px, 1fr)); /* 2 columns, adjust minmax for more */
        gap: 10px; /* Gap between mini-items */
        width: 100%; /* Take full width of parent stat-item */
        justify-content: center;
        align-items: center;
    }

    .breakdown-mini-item {
        background-color: #fff3e0; /* Lighter background for mini-items */
        border: 1px solid #ffb74d;
        border-radius: 5px;
        padding: 8px;
        text-align: center;
        font-size: 0.9em;
        color: #f57c00;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        min-height: 60px; /* Ensure a minimum height for mini-items */
    }
    .breakdown-mini-item .mini-label {
        font-weight: bold;
        margin-bottom: 3px;
    }
    .breakdown-mini-item .mini-count {
        font-size: 1.5em;
        font-weight: bold;
        color: #e65100;
    }

    /* Stage Statistics Table */
    .stage-stats-table {
        width: 100%;
        margin-top: 40px;
        border-collapse: collapse;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .stage-stats-table th, .stage-stats-table td {
        border: 1px solid #ddd;
        padding: 12px;
        text-align: center;
    }
    .stage-stats-table th {
        background-color: #c62828;
        color: white;
        font-size: 1.1em;
    }
    .stage-stats-table tr:nth-child(even) {
        background-color: #f9f9f9;
    }
    .stage-stats-table tr:hover {
        background-color: #f1f1f1;
    }
    .stage-stats-table .stage-name {
        text-align: left;
        font-weight: bold;
    }

    /* Media queries for responsiveness */
    @media (max-width: 600px) {
        .stats-grid {
            flex-wrap: wrap;
            justify-content: center;
        }
        .stat-item {
            max-width: 90%;
            min-width: unset;
            width: 100%;
        }
        .stat-item.breakdown-container {
            text-align: center;
            padding: 20px;
        }
        .breakdown-container h3 {
            text-align: center;
        }
    }
    /* Removed .sub-stats-grid and its items from display:none as they are already removed from PHP output */
</style>

<div class="stats-content-wrapper">
    <h2>Project Statistics</h2>

    <div class="stats-grid">
        <div class="stat-item">
            <h3>Total Projects</h3>
            <span class="stat-value"><?php echo $totalProjects; ?></span>
        </div>
        <div class="stat-item">
            <h3>Projects Done</h3>
            <span class="stat-value done"><?php echo $finishedProjects; ?></span>
            <span class="stat-percentage">(<?php echo $percentageDone; ?>%)</span>
        </div>
        <div class="stat-item">
            <h3>Projects Ongoing</h3>
            <span class="stat-value ongoing"><?php echo $ongoingProjects; ?></span>
            <span class="stat-percentage">(<?php echo $percentageOngoing; ?>%)</span>
        </div>
        <div class="stat-item breakdown-container">
            <h3>Ongoing Breakdown</h3>
            <?php if ($ongoingProjects > 0): ?>
                <div class="breakdown-mini-grid">
                    <?php foreach ($ongoingBreakdownData as $data): ?>
                        <div class="breakdown-mini-item">
                            <span class="mini-label"><?php echo htmlspecialchars($data['name']); ?></span>
                            <span class="mini-count"><?php echo $data['count']; ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <span class="stat-value" style="font-size: 1.1em; color: #f57c00;">All projects finished!</span>
            <?php endif; ?>
        </div>
    </div>

    <h3 style="margin-top: 40px; color: #333;">All Projects by Current Stage (Including Finished)</h3>
    <table class="stage-stats-table">
        <thead>
            <tr>
                <th>Stage Name</th>
                <th>Number of Projects</th>
            </tr>
        </thead>
        <tbody>
            <?php
            foreach ($stagesOrder as $stage) {
                echo "<tr>";
                echo "<td class='stage-name'>" . htmlspecialchars($stage) . "</td>";
                echo "<td>" . ($stageCounts[$stage] ?? 0) . "</td>";
                echo "</tr>";
            }
            echo "<tr>";
            echo "<td class='stage-name'>Finished Projects</td>";
            echo "<td>" . ($stageCounts['Finished'] ?? 0) . "</td>";
            echo "</tr>";
            ?>
        </tbody>
    </table>

    </div>
```