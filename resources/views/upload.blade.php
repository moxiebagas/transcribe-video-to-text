<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Upload Video for Transcription</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        /* Gaya default untuk tombol */
        #uploadButton {
            background-color: #9ca3af; /* Warna abu-abu ketika disabled */
            border: none;
            color: #ffffff;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: not-allowed; /* Ubah kursor menjadi not-allowed ketika disabled */
            transition: background-color 0.3s, box-shadow 0.3s; /* Animasi transisi */
        }

        /* Gaya ketika tombol tidak disabled */
        #uploadButton:not(:disabled) {
            background-color: #1563AE; /* Warna ungu ketika aktif */
            box-shadow: 0px 4px 61px 0px #1563AE66; /* Efek bayangan */
            cursor: pointer; /* Ubah kursor menjadi pointer ketika aktif */
        }

        /* Gaya hover ketika tombol tidak disabled */
        #uploadButton:not(:disabled):hover {
            background-color: #0c579e; /* Warna ungu yang lebih gelap saat hover */
            box-shadow: 0px 4px 61px 0px #0c579e66; /* Efek bayangan saat hover */
        }

        body {
          background: #1f3244;
          min-height: 100vh;
          max-width: 100vw;
          display: flex;
          align-items: center;
          justify-content: center;
          padding: 5vmax;
          box-sizing: border-box;
        }

        div {
          background-color: #efefef;
          padding: 32px;
          border-radius: 10px;
        }

        input[type="file"] {
          position: relative;
          outline: none;

          /* File Selector Button Styles */
          &::file-selector-button {
            border-radius: 4px;
            padding: 0 16px;
            height: 40px;
            cursor: pointer;
            background-color: white;
            border: 1px solid rgba(#000, 0.16);
            box-shadow: 0px 1px 0px rgba(#000, 0.05);
            margin-right: 16px;

            /*
              This is a hack to change the button label. 
              I'm hiding the default label and then 
              manually applying the width based on 
              updated icon and label.
            */
            width: 132px;
            color: transparent;
            
            /*
              Firefox doesn't support the pseudo ::before 
              or ::after elements on this input field so 
              we need to use the @supports rule to enable 
              default styles fallback for Firefox.
            */
            @supports (-moz-appearance: none) {
              color: var(--primary-color);
            }

            &:hover {
              background-color: #f3f4f6;
            }

            &:active {
              background-color: #e5e7eb;
            }
          }

          /* Faked label styles and icon */
          &::before {
            position: absolute;
            pointer-events: none;
            top: 10px;
            left: 16px;
            height: 20px;
            width: 20px;
            content: "";
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='%230964B0'%3E%3Cpath d='M18 15v3H6v-3H4v3c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2v-3h-2zM7 9l1.41 1.41L11 7.83V16h2V7.83l2.59 2.58L17 9l-5-5-5 5z'/%3E%3C/svg%3E");
          }
          
          &::after {
            position: absolute;
            pointer-events: none;
            top: 8px;
            left: 40px;
            color: var(--primary-color);
            content: "Upload File";
          }

          /* Handle Component Focus */
          &:focus-within::file-selector-button,
          &:focus::file-selector-button {
            outline: 2px solid var(--primary-color);
            outline-offset: 2px;
          }
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <h1 class="mb-4" style="text-align: center;">Video Transcription</h1>

        <!-- Form Upload Video -->
        <form id="uploadForm" enctype="multipart/form-data">
          @csrf
          <div class="mb-3">
            <label for="video" class="form-label">Choose a video file:</label>
            <input type="file" class="form-control" id="video" name="video" accept="video/*" required>
            <button type="submit" class="btn btn-primary form-control mt-3" id="uploadButton" disabled>
                <span id="buttonText">Upload File</span>
                <span id="loadingSpinner" class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
            </button>
          </div>
        </form>

        <!-- Tempat untuk menampilkan hasil -->
        <div id="resultContainer" class="mt-5" style="display: none;">
          <!-- Audio Player -->
          <div id="audioContainer" style="display: none;">
              <h2>Audio File:</h2>
              <div class="card">
                  <div class="card-body">
                      <audio controls style="width:100%;">
                          <source id="audioSource" src="" type="audio/wav">
                          Your browser does not support the audio element.
                      </audio>
                  </div>
              </div>
          </div>

          <!-- Hasil Transkripsi -->
          <div id="transcriptContainer" style="display: none;">
              <h2>Transcription Result:</h2>
              <div class="card">
                  <div class="card-body">
                      <p id="transcriptText"></p>
                  </div>
              </div>
          </div>

          <!-- Hasil Ringkasan -->
          <div id="summaryContainer" style="display: none;">
              <h2>Summary:</h2>
              <div class="card">
                  <div class="card-body">
                      <p id="summaryText"></p>
                  </div>
              </div>
          </div>
        </div>

        <!-- Pesan Error -->
        <div id="errorContainer" class="alert alert-danger mt-4" style="display: none;">
          <ul id="errorList"></ul>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script>
      $(document).ready(function() {
          // Event listener untuk perubahan input file
          $('#video').on('change', function() {
              if (this.files.length > 0) {
                  $('#uploadButton').removeAttr('disabled');
              } else {
                  $('#uploadButton').attr('disabled', true);
              }
          });
  
          // Event listener untuk submit form
          $('#uploadForm').on('submit', function(event) {
              event.preventDefault(); // Hentikan perilaku default form
  
              // Tampilkan spinner dan nonaktifkan tombol
              $('#buttonText').addClass('d-none');
              $('#loadingSpinner').removeClass('d-none');
              $('#uploadButton').attr('disabled', true);
  
              // Siapkan data form
              const formData = new FormData(this);
  
              // Kirim data form ke server menggunakan AJAX
              $.ajax({
                  url: "{{ route('process.video') }}", // URL tujuan
                  type: 'POST',
                  data: formData,
                  processData: false,
                  contentType: false,
                  success: function(response) {
                      // Tampilkan hasil transkripsi, ringkasan, dan audio player
                      if (response.audioUrl) {
                          $('#audioSource').attr('src', response.audioUrl);
                          $('#audioContainer').show();
                      }
                      if (response.transcript) {
                          $('#transcriptText').text(response.transcript);
                          $('#transcriptContainer').show();
                      }
                      if (response.summary) {
                          $('#summaryText').text(response.summary);
                          $('#summaryContainer').show();
                      }
                      $('#resultContainer').show();
                      $('#errorContainer').hide();
                  },
                  error: function(xhr) {
                      // Tampilkan pesan error
                      const errorMessage = xhr.responseJSON?.error || 'Gagal memproses video.';
                      $('#errorList').html(`<li>${errorMessage}</li>`);
                      $('#errorContainer').show();
                  },
                  complete: function() {
                      // Sembunyikan spinner dan aktifkan tombol
                      $('#buttonText').removeClass('d-none');
                      $('#loadingSpinner').addClass('d-none');
                      $('#uploadButton').removeAttr('disabled');
                  }
              });
          });
      });
  </script>
</body>
</html>