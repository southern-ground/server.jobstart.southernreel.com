<?php
/**
 * Created by PhpStorm.
 * User: fst
 * Date: 7/31/17
 * Time: 11:00 AM
 */

if (isset($_SERVER['HTTP_ORIGIN'])) {
    //header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
    header("Access-Control-Allow-Origin: *");
    header('Access-Control-Allow-Credentials: true');
    header("Access-Control-Allow-Methods: GET, POST");
}

// Externalized vars:
require_once('api-inc.php');
require_once('api-response-class.php');

$api = new Response();

$params = array();
$parts = explode('/', $_SERVER['REQUEST_URI']);
for ($i = 0; $i < count($parts); $i = $i + 2) {
    isset($parts[$i + 1])
        ?
        $params[$parts[$i]] = $parts[$i + 1]
        :
        $params[$parts[$i]] = null;
}

// Logic
switch (count($params)) {
    case 0:
    case 1:
        $api->error = 500;
        $api->message = "Invalid request.";
        break;
    case 2:
        /*         * Base API
         *
         * ex:  /department/
         *      /depasrtments/
         *      /employees/
         *      /projects/, etc.
         */
        $command = filter_var(array_keys($params)[1], FILTER_SANITIZE_STRING);
        switch ($command) {
            case "department":
                $api->message = "Department List";
                $link = $api->db_connect();
                $departmentId = filter_var($params[$command], FILTER_SANITIZE_STRING);
                if ($result = $link->query("SELECT * FROM departments WHERE id='" . $departmentId . "' ORDER BY department_name")) {
                    $api->departments = array();
                    while ($row = $result->fetch_assoc()) {
                        array_push($api->departments, array("id" => $row["id"], "name" => $row["department_name"]));
                    }
                } else {
                    $result->close();
                    $api->bad_query("Bad query: Department list");
                }
                break;
            case "departments":
                $id = filter_var($params[$command], FILTER_SANITIZE_STRING);
                switch ($id) {
                    case "employees":
                        $api->message = "Employee List by Department";
                        $link = $api->db_connect();
                        $departmentsListResult = $link->query("SELECT * FROM departments WHERE deleted = 0 ORDER BY department_name ");
                        if ($departmentsListResult) {
                            $employeeByDepartmentResult = $link->query("SELECT e.*, d.id, d.department_name " .
                                "FROM employees e, departments d, department_assignments da " .
                                "WHERE e.employee_id = da.employee_id " .
                                "AND d.id = da.department_id " .
                                "ORDER BY d.department_name, e.last_name, e.first_name");
                            if ($employeeByDepartmentResult) {
                                $depts = array();
                                while ($row = $employeeByDepartmentResult->fetch_assoc()) {
                                    $dept = $row['department_name'];
                                    if (!isset($depts[$dept])) {
                                        $depts[$dept] = array();
                                    }
                                    array_push($depts[$dept], array("employee_id" => $row['employee_id'], "first_name" => $row['first_name'], "last_name" => $row['last_name']));
                                }
                                $api->employees = $depts;
                            } else {
                                $link->close();
                                $api->bad_query("Bad query: Employee by Department list");
                            }
                        } else {
                            $link->close();
                            $api->bad_query("Bad query: Employee by Department list");
                        }
                        break;
                    case null:
                        // Get the list of all projects:
                        $api->message = "Department List";
                        $link = $api->db_connect();
                        if ($result = $link->query("SELECT * FROM departments WHERE deleted=0 ORDER BY department_name")) {
                            $api->departments = array();
                            while ($row = $result->fetch_assoc()) {
                                array_push($api->departments, array("id" => $row["id"], "name" => $row["department_name"]));
                            }
                        } else {
                            $result->close();
                            $api->bad_query("Bad query: Department list");
                        }
                        break;
                    default:
                        $api->error = 400;
                        $api->message = "Unable to determine $command directive.";
                }
                break;
            case "employee":
                $api->message = "Employee Detail";
                $directive = filter_var($params[$command], FILTER_SANITIZE_STRING);
                if ($directive) {
                    if ($directive === 'add') {
                        if ($api->type === 'PUT') {
                            // Get the next ID:
                            $link = $api->db_connect();
                            $query = "SELECT MAX(employee_id) AS employee_id FROM employees";
                            $result = $link->query($query);
                            $next_id = $result->fetch_assoc()['employee_id'] += 1;
                            $api->message = $next_id;

                            // Execute the Add:
                            $headers = apache_request_headers();
                            $fp = fopen("php://input", 'r+');
                            $user = json_decode(stream_get_contents($fp));
                            $query = "INSERT into employees "
                                . "(employee_id, first_name, "
                                . "nickname, last_name, "
                                . "email, phone, office_phone, admin, deleted) VALUES "
                                . "('$next_id','$user->first_name', "
                                . "'$user->nickname','$user->last_name', "
                                . "'$user->email', '$user->other_phone', "
                                . "'$user->office_phone', "
                                . "'0', '')";
                            $result = $link->query($query);
                            if ($link->affected_rows === 1) {
                                // User added successfully:
                                // Try to add them to the department list:
                                $query = "INSERT into department_assignments "
                                    . "(employee_id, department_id) VALUES "
                                    . "('$next_id', '$user->department')";
                                $result = $link->query($query);
                                if ($link->affected_rows === 1) {
                                    // Issue the response:
                                    $api->error = 200;
                                    $api->message = "User added";
                                    $api->user_added = $link->affected_rows;
                                    $user = $api->db_connect();
                                    $link = $user->query("SELECT * FROM employees WHERE employee_id='$next_id' LIMIT 1");
                                    $api->user = $link->fetch_assoc();
                                } else {
                                    $api->error = 400;
                                    $api->message = "Unable to add department link for " . $user->first_name . " $query";
                                }
                            } else {
                                $api->error = 400;
                                $api->message = "Unable to add user " . $user->first_name;
                            }
                        } else {
                            $api->error = 400;
                            $api->message = "Request denied.";
                        }
                    } else {
                        $link = $api->db_connect();
                        $query = "SELECT * FROM employees WHERE employee_id = '$directive' LIMIT 1";
                        $deptQuery = "SELECT department_assignments.department_id, departments.department_name FROM department_assignments, departments WHERE employee_id ='$directive' AND department_assignments.department_id = departments.id ORDER BY departments.department_name ASC";
                        $result = $link->query($query);
                        if ($result->num_rows === 1) {
                            $api->employee = $result->fetch_assoc();
                            // Check for department assignments:
                            $deptResult = $link->query($deptQuery);
                            $api->employee['departments'] = array();
                            while ($row = $deptResult->fetch_assoc()) {
                                array_push($api->employee['departments'], $row);
                            }
                        } else {
                            $api->error = 400;
                            $api->message = "No employee with ID $id";
                        }
                    }
                } else {
                    $api->bad_query("Bad query: Employee detail");
                }
                break;
            case "employees":
                $api->message = "Employee List";
                $link = $api->db_connect();
                if ($result = $link->query("SELECT * FROM employees WHERE deleted = 0 ORDER BY last_name, first_name")) {
                    $api->employees = array();
                    while ($row = $result->fetch_assoc()) {
                        array_push($api->employees, $row);
                    }
                } else {
                    $result->close();
                    $api->bad_query("Bad query: Jobs list");
                }
                break;
            case "import":
                $importType = filter_var($params[$command], FILTER_SANITIZE_STRING);
                switch ($importType) {
                    // Experimental Import from Pingboard Data:
                    case "employees":
                        $string = file_get_contents("data/employees.json");
                        $json_a = json_decode($string, true);
                        $added = 0;
                        $link = $api->db_connect();
                        foreach ($json_a["users"] as $index => $user) {
                            $query = "INSERT INTO employees (employee_id, first_name, last_name, nickname, email, phone, office_phone, image) VALUES " .
                                "('" .
                                $user["id"] . "', '" .
                                $user["first_name"] . "', '" .
                                $user["last_name"] . "', '" .
                                $user["nickname"] . "', '" .
                                $user["email"] . "', '" .
                                $user["phone"] . "', '" .
                                $user["office_phone"] . "', '" .
                                $user["avatar_urls"]["medium"] . "')";
                            mysqli_query($link, $query);
                            $employeeAdded = mysqli_affected_rows($link);
                            if ($employeeAdded === 1) {
                                $added++;
                            }
                        }
                        $api->message = "Employee import.";
                        $api->import_count = $added;
                        break;
                    case "departments":
                        $string = file_get_contents("data/groups.json");
                        $json_a = json_decode($string, true);
                        $added = 0;
                        $link = $api->db_connect();
                        foreach ($json_a["groups"] as $index => $group) {
                            $query = "INSERT INTO departments (id, department_name) VALUES " .
                                "('" .
                                $group["id"] . "', '" .
                                $group["name"] . "')";
                            mysqli_query($link, $query);
                            $groupAdded = mysqli_affected_rows($link);
                            if ($groupAdded === 1) {
                                $added++;
                            }
                            // $users = isset($group["links"]["users"]) ? $group["links"]["users"] : array();
                        }
                        $api->message = "Department import.";
                        $api->import_count = $added;
                        break;
                    default:
                        $api->error = 400;
                        $api->message = "Who's a saucy monkey?";
                        break;
                }
                break;
            case "jobs":
                // Get the list of all projects:
                $api->message = "Jobs List";
                $link = $api->db_connect();
                if ($result = $link->query("SELECT * FROM jobs WHERE deleted=0 ORDER BY name")) {
                    $api->job_count = $result->num_rows;
                    $api->jobs = array();
                    while ($row = $result->fetch_assoc()) {
                        array_push($api->jobs, $row);
                    }
                } else {
                    $result->close();
                    $api->bad_query("Bad query: Jobs list");
                }
                break;
            case "job":
                // Get the list of all projects:
                $api->message = "Job Detail";
                $link = $api->db_connect();
                $jobId = filter_var($params[$command], FILTER_SANITIZE_STRING);
                if ($result = $link->query("SELECT * FROM jobs WHERE id=$jobId AND deleted=0 ORDER BY name")) {
                    while ($row = $result->fetch_assoc()) {
                        $api->job = $row;
                    }
                } else {
                    $result->close();
                    $api->bad_query("Bad query: Jobs list");
                }
                break;
            case "add":
                $type = filter_var($params[$command], FILTER_SANITIZE_STRING);
                switch ($type) {
                    case 'job':
                        if ($api->type === 'PUT') {
                            $api->message = "Add Project";
                            $headers = apache_request_headers();
                            $fp = fopen("php://input", 'r+');
                            $job = json_decode(stream_get_contents($fp));

                            $job_guid = $api->GUID();
                            $job_created_date = date("Y-m-d H:i:s");
                            $job_title = filter_var($job->job_title, FILTER_SANITIZE_STRING);
                            $job_department = filter_var($job->creator_department, FILTER_SANITIZE_STRING);
                            $job_due_date = filter_var($job->due_date, FILTER_SANITIZE_STRING);
                            $job_creator_id = filter_var($job->creator_id, FILTER_SANITIZE_STRING);
                            $job_creator_name = filter_var($job->creator_name, FILTER_SANITIZE_STRING);

                            $job_has_creative = filter_var($job->has_creative, FILTER_SANITIZE_STRING);
                            $job_has_photo = filter_var($job->has_photo, FILTER_SANITIZE_STRING);
                            $job_has_pr = filter_var($job->has_pr, FILTER_SANITIZE_STRING);
                            $job_has_social = filter_var($job->has_social, FILTER_SANITIZE_STRING);
                            $job_has_web = filter_var($job->has_web, FILTER_SANITIZE_STRING);

                            $job_deliverables = filter_var($job->job_deliverables, FILTER_SANITIZE_STRING);
                            $job_description = filter_var($job->job_description, FILTER_SANITIZE_STRING);
                            $job_kickoff_availability = filter_var($job->kickoff_availability, FILTER_SANITIZE_STRING);
                            $job_other_people = filter_var($job->other_people, FILTER_SANITIZE_STRING);

                            $link = $api->db_connect();

                            $query = "INSERT INTO jobs ("
                                . "guid, "
                                . "date_created, "
                                . "name, "
                                . "department_id, "
                                . "due_date, "
                                . "creator_id, "
                                . "creator_name, "
                                . "has_creative, "
                                . "has_photo, "
                                . "has_pr, "
                                . "has_social, "
                                . "has_web, "
                                . "job_deliverables, "
                                . "job_description, "
                                . "kickoff_availability, "
                                . "other_people, "
                                . "deleted) VALUES ("
                                . "'$job_guid', "
                                . "'$job_created_date', "
                                . "'$job_title', "
                                . "'$job_department', "
                                . "'$job_due_date', "
                                . "'$job_creator_id', "
                                . "'$job_creator_name', "
                                . "'$job_has_creative', "
                                . "'$job_has_photo', "
                                . "'$job_has_pr', "
                                . "'$job_has_social', "
                                . "'$job_has_web', "
                                . "'$job_deliverables', "
                                . "'$job_description', "
                                . "'$job_kickoff_availability', "
                                . "'$job_other_people', "
                                . "'0')";

                            $result = $link->query($query);
                            if (mysqli_affected_rows($link)) {
                                $api->error = 200;
                                $api->message = "Job added successfully";
                                $api->job_id = mysqli_insert_id($link);
                            }else{
                                $api->error = 400;
                                $api->message = "Could not insert job start.";
                            }
                        } else {
                            $api->message = "I need a title, don't I?";
                        }
                        break;
                    case
                    'employee':
                        /* TODO: Migrate Employee Add to HERE. */
                        break;
                    default:
                        $api->message = "Unknown directive (" . $type . ")";

                }
                break;
            default:
                $api->error = 400;
                $api->message = "I don't know what you're trying to do. Cut it out.";
        }
        break;
    case 3:
        $command = filter_var(array_keys($params)[1], FILTER_SANITIZE_STRING);
        $id = filter_var($params[$command], FILTER_SANITIZE_STRING);
        $directive = filter_var(array_keys($params)[2], FILTER_SANITIZE_STRING);
        switch ($command) {
            case "department":
                if ($directive === "employees") {
                    $api->message = "Employee By Department";
                    $api->department_id = $id;
                    $link = $api->db_connect();
                    // Grab the department name, if found:
                    $query = "SELECT department_name FROM departments WHERE id='" . $id . "' LIMIT 1";
                    $result = $link->query($query);
                    if ($result && mysqli_num_rows($result)) {
                        $api->department_name = $result->fetch_assoc()["department_name"];
                        $employeeQuery = "SELECT * FROM employees INNER JOIN department_assignments ON department_assignments.employee_id = employees.employee_id WHERE department_assignments.department_id = '" . $id . "' ORDER BY employees.last_name, employees.first_name";
                        $employeeResult = $link->query($employeeQuery);
                        $api->employees = array();
                        if ($employeeResult) {
                            $api->employees = array();
                            while ($row = $employeeResult->fetch_assoc()) {
                                array_push($api->employees, $row);
                            }
                        } else {
                            $api->bad_query("Bad query: Employees by Department ($id)");
                        }
                    } else {
                        $api->message = "No such department/group ($id)";
                    }
                } else {
                    $api->error = 400;
                    $api->message = "No data for department ($id)";
                }
                break;
            default:
                $api->error = 400;
                $api->message = "Bad directive ($directive).";
        }
        break;
    default:
        die('I DON"T KNOW WHAT I"M DOING ' . count($params));
}

/* free result set */
if (isset($result) && method_exists($result, 'free_result')) {
    $result->free_result();
}
if (isset($link) && method_exists($link, 'close')) {
    $link->close();
}

$api->autofellate();

/*if (array_key_exists("import", $params)) {

}

if (array_key_exists("add", $params)) {
    switch ($params["add"]) {
        case "project":
            if ($api->type === "POST") {
                if (array_key_exists("project_name", $_POST)) {
                    $link = $api->db_connect();
                    $projectName = filter_var($_POST["project_name"], FILTER_SANITIZE_STRING);
                    mysqli_query($link, "INSERT INTO jobs (guid, name ) VALUES ('" . $api->GUID() . "', '" . $projectName . "');");
                    $api->message = "Created project: " . mysqli_affected_rows($link);
                    $api->info = mysqli_info($link);
                    mysqli_close($link);
                    $api->autofellate();
                } else {
                    $api->error = "400";
                    $api->message = "Invalid project data";
                }
                print_json($api);
            } else {
                // Nothing;
                $api->bad_method();
            }
            break;
        case "user":
            if ($api->type === "POST") {
                $api->message = "Add user";
                $api->autofellate();
            } else {
                $api->bad_method();
            }
            break;
        default:
            // Nothing;
    }

}*/


