/**
 * Voice input for a textarea through the browser's speech recognition
 * (Chrome, Edge, Safari; not Firefox). The recognised words are appended to
 * what is already written and announced with an input event, so Livewire's
 * wire:model and the composer's own handlers see them like typing.
 */
export function argosDictation(textareaId, lang) {
    const Recognition = window.SpeechRecognition ?? window.webkitSpeechRecognition;

    return {
        supported: Recognition !== undefined,
        listening: false,
        failed: '',
        recognition: null,

        toggle() {
            if (this.listening) {
                this.recognition?.stop();

                return;
            }
            const field = document.getElementById(textareaId);
            if (!field || !this.supported) {
                return;
            }
            const before = field.value === '' || /\s$/.test(field.value) ? field.value : `${field.value} `;
            const recognition = new Recognition();
            recognition.lang = lang;
            recognition.continuous = true;
            recognition.interimResults = true;
            recognition.onresult = (event) => {
                let text = '';
                for (const result of event.results) {
                    text += result[0].transcript;
                }
                field.value = before + text.trimStart();
                field.dispatchEvent(new Event('input', { bubbles: true }));
            };
            recognition.onerror = (event) => {
                // "no-speech" and "aborted" just end the session; the rest is worth telling.
                this.failed = ['no-speech', 'aborted'].includes(event.error) ? '' : event.error;
            };
            recognition.onend = () => {
                this.listening = false;
                field.focus();
            };
            this.failed = '';
            this.recognition = recognition;
            recognition.start();
            this.listening = true;
        },
    };
}
