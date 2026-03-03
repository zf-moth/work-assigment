<script setup>
import { ref } from 'vue'

// Reactive state for drag-and-drop interaction
const isDragging = ref(false)

// Loading spinner toggle while the API request is in flight
const isLoading = ref(false)

// Holds the name of the currently selected file (for user feedback)
const selectedFileName = ref('')

// Status message displayed after upload attempt: { type: 'success' | 'error', message: string }
const status = ref({ type: null, message: '' })

/**
 * Drag-enter handler, activates the visual drop-zone highlight.
 */
const onDragEnter = (e) => {
  e.preventDefault()
  isDragging.value = true
}

/**
 * Drag-leave handler, deactivates the drop-zone highlight.
 */
const onDragLeave = (e) => {
  e.preventDefault()
  isDragging.value = false
}

/**
 * Drop handler, reads the first dropped file and delegates to handleFile.
 */
const onDrop = async (e) => {
  e.preventDefault()
  isDragging.value = false
  const files = e.dataTransfer?.files

  if (files && files.length > 0) {
    handleFile(files[0])
  }
}

/**
 * Opens the native file picker by programmatically clicking the hidden input.
 */
const triggerFileSelect = () => {
  document.getElementById('fileUpload').click()
}

/**
 * File-input change handler, delegates the selected file to handleFile.
 */
const onFileChange = (e) => {
  const files = e.target.files
  if (files && files.length > 0) {
    handleFile(files[0])
  }
}

/**
 * Dismiss the current status message (success or error).
 */
const dismissStatus = () => {
  status.value = { type: null, message: '' }
}

/**
 * Core processing pipeline:
 * 1. Validates the file is JSON
 * 2. Parses its contents
 * 3. POSTs the JSON payload to the PHP back-end via the Vite dev-server proxy
 * 4. Receives the generated BEST file as a blob and triggers a download, (TODO: might change later)
 */
const handleFile = async (file) => {
  // Only accept .json files
  if (file.type !== 'application/json' && !file.name.endsWith('.json')) {
    status.value = { type: 'error', message: 'Vyberte prosím platný JSON soubor.' }
    return
  }

  // Show the selected file name for user feedback
  selectedFileName.value = file.name
  status.value = { type: null, message: '' }
  isLoading.value = true

  try {
    const text = await file.text()

    // Attempt to parse, surface clear error if JSON is malformed
    let jsonPayload
    try {
      jsonPayload = JSON.parse(text)
    } catch (err) {
      throw new Error('Neplatný obsah JSON souboru.')
    }

    // Send the parsed orders to the PHP BEST generator
    const response = await fetch('/api/generate.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(jsonPayload)
    })

    if (!response.ok) {
      let errMsg = 'Chyba při komunikaci se serverem.'
      try {
        const errJson = await response.json()
        if (errJson.error) errMsg = errJson.error
      } catch (e) {
        // Response body was not JSON, use the generic message
      }
      throw new Error(errMsg)
    }

    // Trigger browser download of the generated BEST file
    const blob = await response.blob()
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    const baseName = file.name.replace(/\.[^.]+$/, '')
    a.download = `refund_${baseName}.best`
    document.body.appendChild(a)
    a.click()
    document.body.removeChild(a)
    window.URL.revokeObjectURL(url)

    status.value = { type: 'success', message: 'BEST soubor byl úspěšně vygenerován a stažen.' }

  } catch (err) {
    status.value = { type: 'error', message: err.message || 'Došlo k neznámé chybě.' }
  } finally {
    isLoading.value = false
    // Reset the file input so re-uploading the same file triggers onChange again
    document.getElementById('fileUpload').value = ''
  }
}
</script>

<template>
  <!-- Animated gradient background blobs -->
  <div class="bg-blobs">
    <div class="blob blob-1"></div>
    <div class="blob blob-2"></div>
  </div>

  <div class="glass-panel">
    <h1>Zpracování vratek <br> e-shopu</h1>
    <p class="subtitle">Automatické generování formátu BEST pro Komerční banku z vrácených objednávek.</p>

    <!-- Drag-and-drop zone and file picker -->
    <div
      class="drop-zone"
      :class="{ 'active': isDragging }"
      @dragover.prevent
      @dragenter="onDragEnter"
      @dragleave="onDragLeave"
      @drop="onDrop"
      @click="triggerFileSelect"
    >
      <div v-if="isLoading" class="loader"></div>
      <div v-else>
        <div class="drop-zone-icon"><img width="96" height="96" src="https://img.icons8.com/glassmorphism/96/add-file.png" alt="add-file"/></div>
        <div class="drop-zone-text">
          Přetáhněte <span>orders.json</span> pro zpracování vratek
        </div>
        <div class="drop-zone-subtext">Vygeneruje příkazy k úhradě pro objednávky se statusem "refunded"</div>
        <div v-if="selectedFileName" class="selected-file">
          Vybraný soubor: <strong>{{ selectedFileName }}</strong>
        </div>
      </div>

      <input
        id="fileUpload"
        type="file"
        accept=".json,application/json"
        style="display: none;"
        @change="onFileChange"
      >
    </div>

    <!-- Status feedback (success / error) -->
    <div
      v-if="status.message"
      class="status-message"
      :class="{ 'status-error': status.type === 'error', 'status-success': status.type === 'success' }"
    >
      <span>{{ status.message }}</span>
      <button class="dismiss-btn" @click.stop="dismissStatus" aria-label="Zavřít">&times;</button>
    </div>
  </div>

  <!-- Footer -->
  <footer class="app-footer">
    Created for <strong>Creepy Studio</strong> by <strong>Patrik Glomb</strong> &middot; Vue 3 + PHP
  </footer>
</template>

<style src="./style.css"></style>
