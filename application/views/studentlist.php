<div class="content-wrapper">
    <div class="container">
        <div class="title-bar">
            Class Name: 10th Grade
        </div>
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <p style="font-size: 20px; margin: 0;">
                <strong>Total Students:</strong> 30
            </p>
            <p style="font-size: 20px; margin: 0;">
                <strong>Class Teacher:</strong> Mr. John Doe
            </p>
        </div>

        <!-- Class Time Table Upload Button -->
        <button class="btn-timetable" id="timeTableButton">Upload Class Time Table</button>
        <input type="file" class="file-input" id="timeTableFileInput" accept=".pdf, .jpg, .png, .jpeg">

        <!-- View Time Table Button -->
        <button class="btn-view-timetable" id="viewTimeTableButton" style="display: none;">View Class Time Table</button>

        <!-- Time Table URL (will be shown once uploaded) -->
        <div id="timeTableURL" style="display: none;">
            <p><strong>Uploaded Time Table:</strong></p>
            <p id="uploadedFileName"></p>
        </div>

        <div class="table-container">
            <div class="table-scroll">
                <table id="studentTable">
                    <thead>
                        <tr>
                            <th>Sr No</th>
                            <th>Student Name</th>
                            <th>Father Name</th>
                            <th>User ID</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr data-user-id="1001">
                            <td>1</td>
                            <td>John Smith</td>
                            <td>Michael Smith</td>
                            <td>1001</td>
                        </tr>
                        <tr data-user-id="1002">
                            <td>2</td>
                            <td>Jane Doe</td>
                            <td>Richard Doe</td>
                            <td>1002</td>
                        </tr>
                        <tr data-user-id="1003">
                            <td>3</td>
                            <td>Emily Davis</td>
                            <td>Robert Davis</td>
                            <td>1003</td>
                        </tr>
                        <!-- Add more rows as needed -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            let uploadedFile = null;

            // Handle file upload
            document.getElementById("timeTableFileInput").addEventListener("change", function(event) {
                const file = event.target.files[0];
                if (file) {
                    alert(`File uploaded: ${file.name}`);
                    uploadedFile = file;

                    // Display the file name and the view button
                    document.getElementById("uploadedFileName").innerText = `Name: ${file.name}`;
                    document.getElementById("timeTableURL").style.display = "block";
                    document.getElementById("viewTimeTableButton").style.display = "inline-block";
                }
            });

            // View Time Table button functionality
            document.getElementById("viewTimeTableButton").addEventListener("click", function() {
                if (uploadedFile) {
                    const fileURL = URL.createObjectURL(uploadedFile);
                    const fileExtension = uploadedFile.name.split('.').pop().toLowerCase();

                    // Open the file in a new window
                    if (fileExtension === "pdf") {
                        window.open(fileURL, "_blank");
                    } else if (fileExtension === "jpg" || fileExtension === "jpeg" || fileExtension === "png") {
                        const imgWindow = window.open("", "_blank");
                        imgWindow.document.write(`<img src="${fileURL}" width="100%" height="auto">`);
                    } else {
                        alert("Unsupported file format");
                    }
                } else {
                    alert("No timetable uploaded yet.");
                }
            });

            // Add functionality to Time Table button
            document.getElementById("timeTableButton").addEventListener("click", function() {
                document.getElementById("timeTableFileInput").click();
            });
        });
    </script>

    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
        }

        .container {
            width: 80%;
            margin: 0 auto;
            padding: 30px;
            border: 1px solid #ccc;
            border-radius: 5px;
            background-color: white;
        }

        .title-bar {
            background-color: #007bff;
            color: white;
            font-weight: bold;
            text-align: center;
            font-size: 24px;
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 20px;
        }

        .table-container {
            margin-top: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: center;
        }

        table th {
            background-color: #006400;
            color: white;
            padding: 10px;
        }

        table td {
            padding: 8px;
        }

        table tbody tr {
            border-bottom: 1px solid #ddd;
            cursor: pointer;
        }

        table tbody tr:hover {
            background-color: #b0b0b0;
        }

        table tbody tr.selected {
            background-color: #ffe4b2;
        }

        .table-scroll {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #ddd;
        }

        .btn-timetable,
        .btn-view-timetable {
            display: inline-block;
            padding: 10px 20px;
            background-color: #28a745;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 16px;
            text-align: center;
            margin: 20px 0;
            cursor: pointer;
            border: none;
        }

        .btn-timetable:hover,
        .btn-view-timetable:hover {
            background-color: #218838;
        }

        /* Hidden file input */
        .file-input {
            display: none;
        }
    </style>