/*
 * ESP32-CAM (AI-THINKER) + base ESP32-CAM-MB — firmware COMPLETO en un solo archivo.
 * No necesitas descargar nada más: pega este código tal cual en Arduino IDE.
 *
 * Qué hace:
 *  - Sirve video MJPEG en  http://IP-ESP32/stream    (lo usan tu página Monitoreo y Python)
 *  - Sirve foto fija en    http://IP-ESP32/capture   (respaldo / pruebas)
 *  - Activa buzzer+flash en http://IP-ESP32/beep     (lo llama Python al detectar intruso)
 *  - Página de estado en   http://IP-ESP32/          (confirma que está vivo)
 *
 * La IA (YOLO) NO corre aquí: corre en tu PC con python/vigilancia_yolo.py.
 *
 * Cableado maqueta (casa de madera 35x30):
 *  - ESP32-CAM montada en la base ESP32-CAM-MB (esa base es solo para programar por USB).
 *  - Buzzer ACTIVO 5V: (+) -> GPIO14, (-) -> GND. LED flash interno ya está en GPIO4.
 *  - Para operar la maqueta alimenta con 5V 2A al pin 5V (el USB de la MB es solo para programar).
 */

#include "esp_camera.h"
#include <WiFi.h>
#include <WebServer.h>

// ============ CONFIGURA ESTO ============
const char *WIFI_SSID = "TU_WIFI";      // nombre de tu WiFi (el mismo de tu PC)
const char *WIFI_PASS = "TU_CLAVE";    // clave de tu WiFi

// IP FIJA (recomendado: así nunca cambia al reconectar).
// Pon USAR_IP_FIJA en true y ajusta a TU red:
//  - Mira en tu PC la "puerta de enlace" (Windows: ipconfig | Mac: ipconfig getoption en0 router).
//    Ej: si la puerta es 192.168.1.1, deja GATEWAY así y elige IP_FIJA 192.168.1.50.
//  - Elige un número final alto (50-200) que no use otro aparato.
#define USAR_IP_FIJA true
IPAddress IP_FIJA(192, 168, 137, 200);
IPAddress GATEWAY(192, 168, 137, 1);
IPAddress SUBNET(255, 255, 255, 0);
// ========================================

#define BUZZER_PIN 14   // buzzer activo de la maqueta
#define LED_PIN 4       // flash interno del ESP32-CAM

// Pines de la cámara AI-THINKER (van fijos, no los cambies)
#define PWDN_GPIO_NUM  32
#define RESET_GPIO_NUM -1
#define XCLK_GPIO_NUM  0
#define SIOD_GPIO_NUM  26
#define SIOC_GPIO_NUM  27
#define Y9_GPIO_NUM    35
#define Y8_GPIO_NUM    34
#define Y7_GPIO_NUM    39
#define Y6_GPIO_NUM    36
#define Y5_GPIO_NUM    21
#define Y4_GPIO_NUM    19
#define Y3_GPIO_NUM    18
#define Y2_GPIO_NUM    5
#define VSYNC_GPIO_NUM 25
#define HREF_GPIO_NUM  23
#define PCLK_GPIO_NUM  22

WebServer server(80);

// Pitido físico: 3 pulsos (intruso). No bloquea el video.
void beepFisico(int veces = 3, int ms = 350) {
  for (int i = 0; i < veces; i++) {
    digitalWrite(BUZZER_PIN, HIGH);
    digitalWrite(LED_PIN, HIGH);
    delay(ms);
    digitalWrite(BUZZER_PIN, LOW);
    digitalWrite(LED_PIN, LOW);
    delay(150);
  }
}

void handleRoot() {
  String ip = WiFi.localIP().toString();
  String html = "<h1>ESP32-CAM Maqueta OK</h1>"
                "<ul><li>Video: <a href='/stream'>/stream</a></li>"
                "<li>Foto: <a href='/capture'>/capture</a></li>"
                "<li>Buzzer: <a href='/beep'>/beep</a></li></ul>"
                "<p>Usa http://" + ip + "/stream en el Dashboard de Laravel.</p>";
  server.send(200, "text/html", html);
}

void handleCapture() {
  camera_fb_t *fb = esp_camera_fb_get();
  if (!fb) { server.send(500, "text/plain", "Error camara"); return; }
  server.send_P(200, "image/jpeg", (const char *)fb->buf, fb->len);
  esp_camera_fb_return(fb);
}

void handleStream() {
  WiFiClient client = server.client();
  client.print("HTTP/1.1 200 OK\r\n"
               "Content-Type: multipart/x-mixed-replace; boundary=frame\r\n"
               "Access-Control-Allow-Origin: *\r\n\r\n");
  while (client.connected()) {
    camera_fb_t *fb = esp_camera_fb_get();
    if (!fb) break;
    client.print("--frame\r\nContent-Type: image/jpeg\r\nContent-Length: " + String(fb->len) + "\r\n\r\n");
    client.write(fb->buf, fb->len);
    client.print("\r\n");
    esp_camera_fb_return(fb);
    delay(100);  // ~10 fps: basta para YOLO y evita sobrecalentar / saturar la ESP32
  }
}

void handleBeep() {
  beepFisico(3, 350);
  server.send(200, "text/plain", "OK beep maqueta");
}

// Intenta VGA en PSRAM y, si falla (malloc/cam_dma_config), baja solo a QVGA.
// Así el mismo .ino funciona en placas con y sin PSRAM útil.
bool tryConfig(framesize_t fs, uint8_t quality, uint8_t fbCount, bool usePsram) {
  camera_config_t config;
  config.ledc_channel = LEDC_CHANNEL_0;
  config.ledc_timer = LEDC_TIMER_0;
  config.pin_d0 = Y2_GPIO_NUM;
  config.pin_d1 = Y3_GPIO_NUM;
  config.pin_d2 = Y4_GPIO_NUM;
  config.pin_d3 = Y5_GPIO_NUM;
  config.pin_d4 = Y6_GPIO_NUM;
  config.pin_d5 = Y7_GPIO_NUM;
  config.pin_d6 = Y8_GPIO_NUM;
  config.pin_d7 = Y9_GPIO_NUM;
  config.pin_xclk = XCLK_GPIO_NUM;
  config.pin_pclk = PCLK_GPIO_NUM;
  config.pin_vsync = VSYNC_GPIO_NUM;
  config.pin_href = HREF_GPIO_NUM;
  config.pin_sccb_sda = SIOD_GPIO_NUM;
  config.pin_sccb_scl = SIOC_GPIO_NUM;
  config.pin_pwdn = PWDN_GPIO_NUM;
  config.pin_reset = RESET_GPIO_NUM;
  config.xclk_freq_hz = 20000000;
  config.pixel_format = PIXFORMAT_JPEG;
  config.frame_size = fs;
  config.jpeg_quality = quality;
  config.fb_count = fbCount;
#ifdef CAMERA_FB_IN_PSRAM
  config.fb_location = usePsram ? CAMERA_FB_IN_PSRAM : CAMERA_FB_IN_DRAM;
#endif
#ifdef CAMERA_GRAB_LATEST
  config.grab_mode = CAMERA_GRAB_LATEST;
#endif
  esp_err_t err = esp_camera_init(&config);
  Serial.printf("Intento %dx%d q=%d fb=%d %s -> %s\n",
                fs == FRAMESIZE_VGA ? 640 : 320, fs == FRAMESIZE_VGA ? 480 : 240,
                quality, fbCount, usePsram ? "PSRAM" : "DRAM",
                err == ESP_OK ? "OK" : "fallo");
  return err == ESP_OK;
}

bool initCamera() {
  bool psram = psramFound();
  if (psram && tryConfig(FRAMESIZE_VGA, 12, 2, true)) return true;   // ideal maqueta
  if (psram && tryConfig(FRAMESIZE_VGA, 15, 1, true)) return true;   // menos memoria
  if (tryConfig(FRAMESIZE_QVGA, 15, 1, false)) return true;          // mínimo, casi siempre funciona
  return false;
}

void setup() {
  Serial.begin(115200);
  pinMode(BUZZER_PIN, OUTPUT);
  digitalWrite(BUZZER_PIN, LOW);

  // Diagnóstico de memoria (la línea "entry 0x..." del arranque es normal, ignórala)
  Serial.printf("PSRAM: %s | libre heap: %u bytes\n",
                psramFound() ? "SI" : "NO", (unsigned)ESP.getFreeHeap());
  if (psramFound()) Serial.printf("PSRAM total: %u bytes\n", (unsigned)ESP.getPsramSize());

  if (!initCamera()) {
    Serial.println("ERROR: fallo esp_camera_init (ni VGA ni QVGA). Revisa cable flex y alimentación 5V 2A).");
    return;
  }

  if (USAR_IP_FIJA) {
    WiFi.config(IP_FIJA, GATEWAY, SUBNET);
    Serial.println("IP fija: " + IP_FIJA.toString());
  }
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  WiFi.setSleep(false);
  Serial.print("Conectando WiFi");
  while (WiFi.status() != WL_CONNECTED) { delay(500); Serial.print("."); }
  Serial.println("\nWiFi OK. IP: " + WiFi.localIP().toString());
  Serial.println("Video:  http://" + WiFi.localIP().toString() + "/stream");
  Serial.println("Foto:   http://" + WiFi.localIP().toString() + "/capture");
  Serial.println("Buzzer: http://" + WiFi.localIP().toString() + "/beep");

  server.on("/", handleRoot);
  server.on("/capture", handleCapture);
  server.on("/stream", handleStream);
  server.on("/beep", handleBeep);
  server.begin();

  beepFisico(1, 150);  // aviso corto de arranque
}

void loop() {
  server.handleClient();
}
