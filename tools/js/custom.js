// ADD Schools IN THE FORM
$("#add_school").submit(function (event) {
	event.preventDefault();

	// Create a FormData object to handle the file upload and other form data
	let formData = new FormData(this);

	const url = $(this).attr("action");

	$.ajax({
		url: url,
		type: "POST",
		data: formData,
		processData: false, // Prevent jQuery from automatically transforming the data into a query string
		contentType: false, // Set the content type to false to let the browser set it
		success: function (response) {
			console.log("Server Response:", response); // Log the response for debugging
			// if(response == '1'){
			//     alert('School Successfully Added');
			//     setTimeout(function(){
			//         location.reload();
			//     }, 1000);
			if (response.trim() == "1") {
				alert("School Successfully Added");
				setTimeout(function () {
					location.reload();
				}, 1000);
			} else {
				alert("School is Not Added");
			}
		},
		error: function (jqXHR, textStatus, errorThrown) {
			console.log("Error:", textStatus, errorThrown);
			alert("An error occurred while adding the school.");
		},
	});

	return false;
});

$("#edit_school").submit(function (event) {
	event.preventDefault();

	const formData = new FormData(this);
	const url = $(this).attr("action");

	$.ajax({
		url: url,
		type: "POST",
		data: formData,
		processData: false,
		contentType: false,
		success: function (response) {
			if (response.match("1")) {
				alert("School Successfully Updated");
				setTimeout(function () {
					window.location.href = BASE_URL + "index.php/schools/manage_school";
				}, 1000);
			} else {
				alert("School is Not Updated");
			}
		},
	});

	return false;
});



//FILE UPLOAD IN THE CLASSES
// $(document).ready(function() {
//     // Trigger file input when the custom button is clicked
//     $('.file-input-wrapper button').on('click', function() {
//         $(this).siblings('.file-input').click();
//     });

//     // Form submission via AJAX
//     $('form').on('submit', function(event) {
//         event.preventDefault(); // Prevent default form submission
//         var formData = new FormData(this);

//         $.ajax({
//             url: $(this).attr('action'),
//             type: 'POST',
//             data: formData,
//             contentType: false,
//             processData: false,
//             beforeSend: function() {
//                 $('#upload-status').html('<div class="spinner-border" role="status"><span class="sr-only">Loading...</span></div>');
//             },
//             success: function(response) {
//                 // Assuming your server returns a success message
//                 $('#upload-status').html('<div class="success-message">Timetable uploaded successfully!</div>');
//             },
//             error: function(response) {
//                 // Assuming your server returns an error message
//                 $('#upload-status').html('<div class="error-message">Failed to upload timetable. Please try again.</div>');
//             }
//         });
//     });
// });

//////////////////////////STUDENT Details//////////////////////////////////////////

$("#add_student_form").submit(function (event) {
	event.preventDefault(); // Prevent the default form submission

	const data = {
		"User Id": $("#user_id").val(),
		Name: $("#sname").val(),
		"Father Name": $("#fname").val(),
		"Mother Name": $("#mname").val(),
		Email: $("#email_user").val(),
		DOB: $("#dob").val(),
		"Phone Number": $("#phone").val(),
		Gender: $("#gender").val(),
		"School Name": $("#school_name").val(),
		Class: $("#class").val(),
		Section: $("#section").val(),
		Address: $("#address").val(),
		Password: $("#password").val(),
	};

	const url = $(this).attr("action");
	$.post(url, data, function (response) {
		if (response == "1") {
			alert("Student Successfully Registered");
			setTimeout(function () {
				location.reload();
			}, 1000);
		} else {
			alert("Student Not Registered");
		}
	});
	return false;
});

$("#add_student_form_offline").submit(function (event) {
	event.preventDefault(); // Prevent the default form submission

	const data = {
		User_Id: $("#user_id").val(),
		Name: $("#sname").val(),
		Father_Name: $("#fname").val(),
		Mother_Name: $("#mname").val(),
		Email: $("#email_user").val(),
		DOB: $("#dob").val(),
		Phone_Number: $("#phone").val(),
		Gender: $("#gender").val(),
		School_Name: $("#school_name").val(),
		Class: $("#class").val(),
		Section: $("#section").val(),
		Address: {
			Street: $("#street").val(),
			City: $("#city").val(),
			State: $("#state").val(),
			PostalCode: $("#postal_code").val(),
		},
		Password: $("#password").val(),
	};

	const url = $(this).attr("action");
	$.post(url, data, function (response) {
		if (response == "1") {
			alert("Student Successfully Registered");
			setTimeout(function () {
				location.reload();
			}, 1000);
		} else {
			alert("Student Not Registered");
		}
	});

	return false;
});

// $("#edit_student_form").submit(function (event) {
// 	event.preventDefault(); // Prevent the default form submission

// 	const data = {
// 		"User Id": $("#user_id").val(),
// 		Name: $("#sname").val(),
// 		"Father Name": $("#fname").val(),
// 		"Mother Name": $("#mname").val(),
// 		Email: $("#email_user").val(),
// 		DOB: $("#dob").val(),
// 		"Phone Number": $("#phone").val(),
// 		Class: $("#class").val(),
// 		Section: $("#section").val(),
// 		Gender: $("#gender").val(),
// 		"School Name": $("#school_name").val(),
// 		Address: $("#address").val(),
// 		Password: $("#password").val(),
// 	};

// 	// Create formattedData object
// 	let formattedData = {};
// 	for (let key in data) {
// 		if (data.hasOwnProperty(key)) {
// 			let formattedKey = key.replace(/_/g, " ");
// 			formattedData[formattedKey] = data[key];
// 		}
// 	}

// 	const url = $(this).attr("action");
// 	$.post(url, formattedData, function (response) {
// 		if (response.match("1")) {
// 			alert("Student Successfully Updated");
// 			setTimeout(function () {
// 				window.location.href =
// 					BASE_URL + "index.php/student/student_registration";
// 			}, 1000);
// 		} else {
// 			alert("Student Not Updated");
// 		}
// 	});

// 	return false;
// });

//////////////////////////STAFF Details//////////////////////////////////////////

$("#add_staff_form").submit(function (event) {
	event.preventDefault(); // Prevent the default form submission

	const data = {
		"User Id": $("#user_id").val(),
		Name: $("#name").val(),
		"School Name": $("#school_name").val(),
		Gender: $("#gender").val(),
		Email: $("#email").val(),
		"Phone Number": $("#phone_number").val(),
		Password: $("#password").val(),
		Address: $("#address").val(),
	};

	const url = $(this).attr("action");
	$.post(url, data, function (response) {
		if (response.match("1")) {
			alert("Staff Successfully Added");
			setTimeout(function () {
				location.reload();
			}, 1000);
		} else {
			alert("Staff Not Added");
		}
	});
	return false;
});

// //Update Staff details
$("#edit_staff_form").submit(function (event) {
	event.preventDefault();
	const data = {
		"User Id": $("#user_id").val(),
		Name: $("#name").val(),
		"School Name": $("#school_name").val(),
		Gender: $("#gender").val(),
		Email: $("#email").val(),
		"Phone Number": $("#phone_number").val(),
		Password: $("#password").val(),
		Address: $("#address").val(),
	};

	const url = $(this).attr("action");
	$.post(url, data, function (response) {
		//alert(fb);
		if (response.match("1")) {
			alert("Staff Successfully Updated");
			setTimeout(function () {
				window.location.href = BASE_URL + "index.php/staff/manage_staff";
			}, 1000);
		} else {
			alert("Staff Not Updated");
		}
	});
	return false;
});

///////////////Account/////////////

// JavaScript: Custom.js

// $(document).ready(function () {
// 	// DataTables initialization
// 	$(".example").DataTable();

// 	// Submit form via AJAX
// 	$("#add_fees_title").submit(function (event) {
// 		event.preventDefault(); // Prevent default form submission

// 		const formData = new FormData(this);
// 		const url = $(this).attr("action");

// 		$.ajax({
// 			url: url,
// 			type: "POST",
// 			data: formData,
// 			processData: false,
// 			contentType: false,
// 			success: function (response) {
// 				// response = response.trim(); // Trim any whitespace
// 				if (response.match("1")) {
// 					$("#fee_title").val("");
// 					location.reload(); // Clear input field
// 					//fetchFeesList(); // Fetch and update fees list
// 				} else {
// 					alert("Fees Title is Not Updated");
// 				}
// 			},
// 			error: function () {
// 				alert("Error occurred while updating Fees Title");
// 			},
// 		});

// 		return false;
// 	});

// 	// Function to fetch and update fees list
// 	// function fetchFeesList() {

// 	//     $.ajax({
// 	//         url: url,
// 	//         type: 'GET',
// 	//         success: function(data) {
// 	//             $('#feesList tbody').html(data);  // Update fees list body
// 	//             $('.example').DataTable().destroy(); // Destroy existing DataTable
// 	//             $('.example').DataTable(); // Reinitialize DataTable
// 	//         },
// 	//         error: function() {
// 	//             alert('Error fetching fees list');
// 	//         }
// 	//     });
// 	// }

// 	// Refresh button event listener
// 	$("#refresh_button").click(function () {
// 		location.reload(); // Refresh the page
// 	});
// });
