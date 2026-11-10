
const camera = document.getElementById("camera");
const canvas = document.getElementById("canvas");
const photoPreview = document.getElementById("photoPreview");

const startCameraBtn = document.getElementById("startCamera");
const capturePhotoBtn = document.getElementById("capturePhoto");
const retakePhotoBtn = document.getElementById("retakePhoto");

const capturedImage = document.getElementById("capturedImage");
const reportForm = document.getElementById("reportForm");

let cameraStream = null;


// 1. OPEN THE CAMERA

async function openCamera() {

    try {

        // Request permission to use the device camera
        cameraStream = await navigator.mediaDevices.getUserMedia({

            video: {
                facingMode: {
                    ideal: "environment"
                }
            },

            audio: false

        });

        // Display the live camera feed
        camera.srcObject = cameraStream;

        camera.hidden = false;
        photoPreview.hidden = true;

        capturePhotoBtn.disabled = false;
        retakePhotoBtn.disabled = true;

    } catch (error) {

        alert("Unable to access camera. Please allow camera permission.");

        console.error("Camera error:", error);

    }

}


// 2. CAPTURE A PHOTOGRAPH

function capturePhoto() {

    if (!cameraStream || !camera.videoWidth) {

        alert("Please wait for the camera to start.");

        return;

    }

    // Get the actual camera dimensions
    canvas.width = camera.videoWidth;
    canvas.height = camera.videoHeight;

    // Draw the camera image onto the canvas
    const context = canvas.getContext("2d");

    context.drawImage(
        camera,
        0,
        0,
        canvas.width,
        canvas.height
    );

    // Convert the captured image into JPEG format
    const imageData = canvas.toDataURL("image/jpeg", 0.85);

    // Store the image for PHP to receive
    capturedImage.value = imageData;

    // Display the captured photograph
    photoPreview.src = imageData;

    photoPreview.hidden = false;
    camera.hidden = true;

    // Stop the camera after taking the photograph
    stopCamera();

    capturePhotoBtn.disabled = true;
    retakePhotoBtn.disabled = false;

}


// 3. STOP THE CAMERA

function stopCamera() {

    if (cameraStream) {

        cameraStream.getTracks().forEach(track => {

            track.stop();

        });

        cameraStream = null;

    }

}


// 4. RETAKE THE PHOTOGRAPH

async function retakePhoto() {

    capturedImage.value = "";

    photoPreview.src = "";

    photoPreview.hidden = true;

    camera.hidden = false;

    await openCamera();

}


// 5. CONNECT BUTTONS TO FUNCTIONS

startCameraBtn.addEventListener("click", openCamera);

capturePhotoBtn.addEventListener("click", capturePhoto);

retakePhotoBtn.addEventListener("click", retakePhoto);


// 6. PREVENT SUBMISSION WITHOUT A PHOTOGRAPH

reportForm.addEventListener("submit", function(event) {

    if (capturedImage.value === "") {

        event.preventDefault();

        alert("Please capture a photograph of your lost item.");

    }

});


// Stop camera if user leaves the page

window.addEventListener("pagehide", stopCamera);
