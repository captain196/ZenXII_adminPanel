// ADD Schools IN THE FORM
$('#add_school').submit(function(event){
    event.preventDefault();

    // Create a FormData object to handle the file upload and other form data
    let formData = new FormData(this);

    const url = $(this).attr('action');

    $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        processData: false,  // Prevent jQuery from automatically transforming the data into a query string
        contentType: false,  // Set the content type to false to let the browser set it
        success: function(response) {
            console.log('Server Response:', response);  // Log the response for debugging
            // if(response == '1'){
            //     alert('School Successfully Added');
            //     setTimeout(function(){
            //         location.reload();
            //     }, 1000);
            if(response.trim() == '1'){
                alert('School Successfully Added');
                setTimeout(function(){
                    location.reload();
                }, 1000);
            }
            else{
                alert('School is Not Added');
            }
        },
        error: function(jqXHR, textStatus, errorThrown) {
            console.log('Error:', textStatus, errorThrown);
            alert('An error occurred while adding the school.');
        }
    });

    return false;
});


$('#edit_school').submit(function(event){
    event.preventDefault();
    
    const formData = new FormData(this);
    const url = $(this).attr('action');
    
    $.ajax({
        url: url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if(response.match('1')) {
                alert('School Successfully Updated');
                setTimeout(function(){
                    window.location.href = BASE_URL + 'index.php/schools/manage_school';
                }, 1000);
            } else {
                alert('School is Not Updated');
            }
        }
    });
    
    return false;
});




    
 // Add Classes in the form///////////////////////////
    $('#add_class_form').submit(function(event) {
        event.preventDefault();

        // Check if the section input is empty
            let sectionInput = $('#section').val().trim();
            if (sectionInput === '') {
                sectionInput = 'A';
                $('#section').val(sectionInput);
            }
            
        // Create a FormData object to handle the file upload and other form data
        let formData = new FormData(this);

        const url = $(this).attr('action');

        $.ajax({
            url: url,
            type: 'POST',
            data: formData,
            processData: false,  // Prevent jQuery from automatically transforming the data into a query string
            contentType: false,  // Set the content type to false to let the browser set it
            success: function(response) {
                if (response == '1') {
                    alert('Class Successfully Added');
                    setTimeout(function() {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Class is Not Added');
                }
            },
            error: function(jqXHR, textStatus, errorThrown) {
                console.log('Error:', textStatus, errorThrown);
                alert('An error occurred while adding the class.');
            }
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
    $('#add_student_form').submit(function(event) {
        event.preventDefault(); // Prevent the default form submission

        const data = {
            'User Id': $('#user_id').val(),
            'Name': $('#sname').val(),
            'Father Name': $('#fname').val(),
            'Mother Name': $('#mname').val(),
            'Email': $('#email_user').val(),
            'DOB': $('#dob').val(),
            'Phone Number': $('#phone').val(),
            'Gender': $('#gender').val(),
            'School Name': $('#school_name').val(),
            'Class': $('#class').val(),
            'Section': $('#section').val(),
            'Address': $('#address').val(),
            'Password': $('#password').val()
        };
    

        const url = $(this).attr('action');
        $.post(url, data, function(response) {
            if (response == '1') {
                alert('Student Successfully Registered');
                setTimeout(function() {
                    location.reload();
                }, 1000);
            } else {
                alert('Student Not Registered');
            }
        });
        return false; 
    });



$('#edit_student_form').submit(function(event) {
    event.preventDefault(); // Prevent the default form submission

    const data = {
        'User Id': $('#user_id').val(),
        'Name': $('#sname').val(),
        'Father Name': $('#fname').val(),
        'Mother Name': $('#mname').val(),
        'Email': $('#email_user').val(),
        'DOB': $('#dob').val(),
        'Phone Number': $('#phone').val(),
        'Class': $('#class').val(),
        'Section': $('#section').val(),
        'Gender': $('#gender').val(),
        'School Name': $('#school_name').val(),
        'Address': $('#address').val(),
        'Password': $('#password').val()
    };

    // Create formattedData object
    let formattedData = {};
    for (let key in data) {
        if (data.hasOwnProperty(key)) {
            let formattedKey = key.replace(/_/g, ' ');
            formattedData[formattedKey] = data[key];
        }
    }

    const url = $(this).attr('action');
    $.post(url, formattedData, function(response) {
        if (response.match('1')) {
            alert('Student Successfully Updated');
            setTimeout(function() {
                window.location.href = BASE_URL + 'index.php/student/student_registration';
            }, 1000);
        } else {
            alert('Student Not Updated');
        }
    });

    return false;
});

//////////////////////////STAFF Details//////////////////////////////////////////

$('#add_staff_form').submit(function(event) {
    event.preventDefault(); // Prevent the default form submission

    const data ={
        'User Id': $('#user_id').val(),
        'Name': $('#name').val(),
        'School Name': $('#school_name').val(),
        'Gender': $('#gender').val(),
        'Email': $('#email').val(),
        'Phone Number': $('#phone_number').val(),
        'Password': $('#password').val(),
        'Address': $('#address').val()
    };

        const url = $(this).attr('action');
        $.post(url, data, function(response){
            if(response.match('1')) {
                alert('Staff Successfully Added');
                setTimeout(function(){
                    location.reload();
                }, 1000);
            } else {
                alert('Staff Not Added');
            }
        }); 
        return false; 
    });


// //Update Staff details
$('#edit_staff_form').submit(function(event) {
    event.preventDefault();
    const data ={
        'User Id': $('#user_id').val(),
        'Name': $('#name').val(),
        'School Name': $('#school_name').val(),
        'Gender': $('#gender').val(),
        'Email': $('#email').val(),
        'Phone Number': $('#phone_number').val(),
        'Password': $('#password').val(),
        'Address': $('#address').val()
    };

   const url = $(this).attr('action');
    $.post(url, data, function(response)  
        {
        //alert(fb);
            if(response.match('1'))
                {
                    alert('Staff Successfully Updated');
                    setTimeout(function(){
                        window.location.href = BASE_URL+'index.php/staff/manage_staff';
                    },1000 );
            }   
            else
            {
                alert('Staff Not Updated');
            }
        });         
        return false;
});

 
///////////////Account/////////////

// JavaScript: Custom.js

$(document).ready(function() {
     // DataTables initialization
     $('.example').DataTable();

    // Submit form via AJAX
    $('#add_fees_title').submit(function(event) {
        event.preventDefault();  // Prevent default form submission
        
        const formData = new FormData(this);
        const url = $(this).attr('action');

        $.ajax({
            url: url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                // response = response.trim(); // Trim any whitespace
                if (response.match('1')) {
                    $('#fee_title').val(''); // Clear input field
                    fetchFeesList(); // Fetch and update fees list
                } else {
                    alert('Fees Title is Not Updated');
                }
            },
            error: function() {
                alert('Error occurred while updating Fees Title');
            }
        });

        return false;
    });

    // Function to fetch and update fees list
    function fetchFeesList() {

        $.ajax({
            url: url,
            type: 'GET',
            success: function(data) {
                $('#feesList tbody').html(data);  // Update fees list body
                $('.example').DataTable().destroy(); // Destroy existing DataTable
                $('.example').DataTable(); // Reinitialize DataTable
            },
            error: function() {
                alert('Error fetching fees list');
            }
        });
    }

    // Refresh button event listener
    $('#refresh_button').click(function() {
        location.reload(); // Refresh the page
    });
});


// $(document).ready(function() {
// $('#add_fees_title').submit(function(event){
//     event.preventDefault();
    
//     const formData = new FormData(this);
//     const url = $(this).attr('action');
    
//     $.ajax({
//         url: url,
//         type: 'POST',
//         data: formData,
//         processData: false,
//         contentType: false,
//         success: function(response) {
//             if (response == '1') {
//                 alert('Fees Title Successfully Updated');
//                  // Clear input field
//                  $('#fee_title').val('');
//                  // Fetch and update fees list
//                  fetchFeesList();
//             } else {
//                 alert('Fees Title is Not Updated');
//             }
//         },
//         error: function() {
//             alert('Error occurred while updating Fees Title');
//         }
//     });
    
//     return false;
// });

// });



// //UPDATE THE DATA IN THE FORM
// $('#update_sub_cat').submit(function(event) {
//     name =$('#name').val();
//     url= $(this).attr('action');
//     $.post(url,{'name':name},function(fb){
//         if(fb.match('1')){
//             alert('Category Successfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL+"index.php/school/category";
//             },1000);
//         }
//         else{
//             alert('Error updating category'); 
//         }
//     });
//     return false;
// });

// // ADD Class IN THE FORM
// $('#add_class_form').submit(function(){
//     name = $('#name').val();
//     cat = $('#cat').val();


//     url =  $(this).attr('action');
//     $.post(url, {'name':name,'cat':cat},function(fb){
        
//         if(fb.match('1')){
//             alert('Class Successfully Added');
//             setTimeout(function(){
//                 location.reload();
//             }, 1000);
//         }
//         else{
//             alert(fb)
//         }
//     });
//     return false;
// });


// // UPDATE Class IN THE FORM
// $('#update_class').submit(function(){
//     name = $('#name').val();
//     cat = $('#cat').val();
//     url =  $(this).attr('action');
//     $.post(url, {'name':name,'cat':cat},function(fb){
//         // alert(fb)    // Here we used for testing purpose
//         // return false;  // Here we used for testing purpose
//         if(fb.match('1')){
//             alert('Class Successfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL+'index.php/school/classes';
//             }, 1000);
//         }
//         else{
//             alert(fb)
//         }
//     });
//     return false;
// });

// // Enter the data of course from the FORM to the database
// $('#add_course_form').submit(function(){
//     name = $('#name').val();
//     duration = $('#course_duration').val();
//     fees = $('#course_fees').val();
//     started = $('#course_started').val();

//     url =  $(this).attr('action');
//     $.post(url, {'name':name,'duration':duration,'fees':fees,'started':started},function(fb){
//         if(fb.match('1'))
//         {
//             alert('Course Successfully Added');
//             setTimeout(function(){
//                 location.reload();
//             }, 1000);
//         }
//         else{
//             alert(fb);
//         }
//     });
//     return false;
// });


// // Enter the data of course from the FORM to the database
// $('#update_course').submit(function(){
//     name = $('#name').val();
//     duration = $('#course_duration').val();
//     fees = $('#course_fees').val();
//     started = $('#course_started').val();

//     url =  $(this).attr('action');
//     $.post(url, {'name':name,'duration':duration,'fees':fees,'started':started},function(fb){
//         if(fb.match('1'))
//         {
//             alert('Course Successfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL+'index.php/school/course';
//             }, 1000);
//         }
//         else{
//             alert(fb);
//         }
//     });
//     return false;
// });




// $('#edit_student_form').submit(function(){
//     url = $(this).attr('action');
//     data = {'name':$('#sname').val(),
//     'fname':$('#fname').val(),
//     'category':$('#category').val(),
//     'class':$('#class').val(),
//     'dob':$('#dob').val(),
//     'join_date':$('#join_date').val(),
//     'email':$('#email_user').val(),
// };

// $.post(url, data, function(fb)  
// {
//     //alert(fb);
//     if(fb.match('1'))
//     {
//         alert('Student Successfully Updated');
//         setTimeout(function(){
//             window.location.href = BASE_URL+'index.php/student/student_registration';
//         });
//     }   
//     else
//         {
//             alert('Student Not Updated');
//         }
// }); 

//     return false;
// });




   

// $('#add_Attendance_form').submit(function(){
//     var url = $(this).attr('action');
//     //get the data from url     
//     var status = $('#status').val();
//     var date = $('#date').val();
//     var remarks = $('#remarks').val();
//     var data = {'status':status, 'date':date, 'remarks':remarks};
//     //console.log(data);
//     $.post(url,data,function(fb){
//        // console.log(fb); // it will send address to the variable fb now in controller will receive it.
//        if(fb.match('1'))
//        {
//            alert('Attendance Successfully Added');
//            setTimeout(function(){
//                 location.reload();
//             },1000 );
//     }   
//    else
//    {
//        alert('Attendance Not Added');
//    }
//     });
//     // alert(url);
//     return false;
// });


// //Update Attendance details
// $('#edit_Attendance_form').submit(function(){
//     url = $(this).attr('action');
//     var status = $('#status').val();
//     var date = $('#date').val();
//     var remarks = $('#remarks').val();
//     var student_id =$('#student_id').val(); 
//     var data = {'status':status, 'date':date, 'remarks':remarks};

//     $.post(url,data,function(fb){
//         // console.log(fb);
//         if(fb.match('1'))
//         {
//             alert('Attendance Successfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL+ 'index.php/attendance/add_attendance/' + student_id;  
//              },1000 );
//      }   
//     else
//     {
//         alert('Attendance Not Updated');
//     }
// });
//      // alert(url);
//      return false;

// });

// //Exam ............................. Code Started.................

// $(document).on('change','#exam_category',function(){   // I want to fetch the data of particular id whose name is 'exam_category'
//     data = $(this).val();
//    // alert(data);
//    //Ajax code below
//    $.post(BASE_URL + 'index.php/exam/find_class',{'cat':data}, function(fb){
//         $('#class').html(fb);
//    })
// });

// $('#add_exam_form').submit(function(){
//     url = $(this).attr('action');
//     // alert(url);
//     title = $('#title').val();
//     start_date = $('#start_date').val();
//     exam_category = $('#exam_category').val();
//     class1 = $('#class').val();

//     //now we will create object with name data
//     data = {'title': title, 'start_date':start_date,'category':exam_category,'class':class1};
//     //console.log(data); 

//     // now this data we got from url will get insert into database through ajax
//     $.post(url, data, function(fb){
//        // alert(fb);
//        if(fb.match('1'))
//         {
//             alert('Exam Succesfully Added');
//             setTimeout(function(){
//                 location.reload();
//             },1000);
//         }
//         else
//         {
//             alert('Exam is not Added');
//         }
//     });

//     return false;
// });


// $('#update_exam_form').submit(function(){
    
//     url = $(this).attr('action');
//     // alert(url);
//     title = $('#title').val();
//     start_date = $('#start_date').val();
//     exam_category = $('#exam_category').val();
//     class1 = $('#class').val();

//     //now we will create object with name data
//     data = {'title': title, 'start_date':start_date,'category':exam_category,'class':class1};
//     //console.log(data); 

//     // now this data we got from url will get insert into database through ajax
//     $.post(url, data, function(fb){
//        // alert(fb);
//        if(fb.match('1'))
//         {
//             alert('Exam Succesfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL + 'index.php/exam/add_exam';
//             },1000);
//         }
//         else
//         {
//             alert('Exam is not Updated');
//         }
//     });
    
//     return false;
// });


// $('#add_time_table_form').submit(function(){
//     url = $(this).attr('action');
//     //alert(url);
//     $.ajax({
//         type:'POST',
//         url:url,
//         data : new FormData($(this)[0]),
//         contentType:false,
//         processData : false,
//         success :function(fb){
//             // later the code is added
//             if(fb.match('1'))
//                 {
//                 alert('Time Table Successfully Uploaded');
//                 setTimeout(function(){
//                     location.reload();
//                 },1000);
//             }
//             else if(fb.match('2'))
//                 alert('Only JPG And PNG PDF File Are Allowed');
//             else
//                 alert('Time Table NOt Upload');

//         }
//     });
//     return false;
// });

// $('#edit_time_table_form').submit(function(){

//     url = $(this).attr('action');
//     //alert(url);
//     $.ajax({
//         type:'POST',
//         url:url,
//         data : new FormData($(this)[0]),
//         contentType:false,
//         processData : false,
//         success :function(fb){
//             console.log(fb);
//             if(fb == '1')
//                 {
//                 alert('Time Table Successfully Uploaded');
//                 setTimeout(function(){
//                     window.location.href = BASE_URL + 'index.php/exam/add_time_table';
//                 },1000);
//             }
//             else if(fb.match('2'))
//                 alert('Only JPG And PNG PDF File Are Allowed');
//             else
//                 alert('Time Table NOt Upload');

//         }
//     });

//     return false;
// });

// //******************************************************** */
// $(document).on('change','#select_student',function(){
//     //alert($(this).val())
//     id = $('#select_student').val();
//     class1 = $('#st_'+id).attr('data-val');
//     // alert(class1);
//     $.post(BASE_URL+ 'index.php/result/find_exams',{'class':class1}, function(fb){
//       //  alert(fb);
//       $('#exam').html(fb);
//     });
    
// });



// //Now to enter the details from the FORM into  the database
// $('#add_result_form').submit(function(){
//     url =$(this).attr('action');
//     //alert(url);  

//     // now we directly fetch the whole data from the FORM , now we use a function---> data = new FormData($(this)[0])
//     data = {'student_id':$('#select_student').val(),
//             'exam_name':$('#exam').val(),
//             'result':$('#result').val(),
//             };

//     $.post(url, data, function(fb){
//         // alert(fb);
//         if(fb.match('1'))
//         {
//             alert('Result Successfully Added');
//             setTimeout(function(){
//                 location.reload();
//             },1000);
//         }
//         else 
//         {
//             alert('Result Not Added');
//         }
//     });

//     return false;
// });


// //Now to Update Results into  the database when User click on the Update button
// $('#edit_result_form').submit(function(){
//     url =$(this).attr('action');
//     //alert(url);  

//     // now we directly fetch the whole data from the FORM , now we use a function---> data = new FormData($(this)[0])
//     data = {'student_id':$('#select_student').val(),
//             'exam_name':$('#exam').val(),
//             'result':$('#result').val(),
//             };

//     $.post(url, data, function(fb){
//         // alert(fb);
//         if(fb.match('1'))
//         {
//             alert('Result Successfully Updated');
//             setTimeout(function(){
//                 window.location.href = BASE_URL + 'index.php/result';
//             },1000);
//         }
//         else 
//         {
//             alert('Result Not Updated');
//         }
//     });

//     return false;
// });


// // code for Status 
// $(document).on('change','.change_status',function(){
//     // alert('sample');
//     tbl = $(this).attr('data-table');
//     id = $(this).attr('data-id');
//     data = $("input[name = 'status_"+ id +"']:checked").val();
//     if(data != 1)
//         data = 0;
//     $.post(BASE_URL + 'index.php/admin/change_status/' + tbl + '/' + id, {'status': data}, function(fb){
//        // alert(fb)
//        if(fb.match('1'))
//         {
//             alert("Status Successfully Changed");
//         }
//         else
//         {
//             alert("Status Not Changed");
//         }
//     });

// });
