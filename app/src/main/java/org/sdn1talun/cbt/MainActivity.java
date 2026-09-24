package org.sdn1talun.cbt;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.ActivityManager;
import android.app.DownloadManager;
import android.content.BroadcastReceiver;
import android.content.Context;
import android.content.Intent;
import android.content.IntentFilter;
import android.content.pm.PackageManager;
import android.graphics.Bitmap;
import android.net.ConnectivityManager;
import android.net.Network;
import android.net.NetworkCapabilities;
import android.net.NetworkRequest;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Build;
import android.os.Bundle;
import android.os.Environment;
import android.os.Handler;
import android.os.Looper;
import android.view.KeyEvent;
import android.view.View;
import android.view.WindowInsets;
import android.view.WindowInsetsController;
import android.view.WindowManager;
import android.webkit.CookieManager;
import android.webkit.GeolocationPermissions;
import android.webkit.JsResult;
import android.webkit.PermissionRequest;
import android.webkit.SslErrorHandler;
import android.webkit.ValueCallback;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceError;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.Toast;

import androidx.activity.OnBackPressedCallback;
import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.annotation.NonNull;
import androidx.appcompat.app.AppCompatActivity;
import androidx.core.content.ContextCompat;
import androidx.core.content.FileProvider;
import androidx.core.graphics.Insets;
import androidx.core.view.ViewCompat;
import androidx.core.view.WindowInsetsCompat;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.button.MaterialButton;
import com.google.android.material.dialog.MaterialAlertDialogBuilder;
import com.google.android.material.progressindicator.LinearProgressIndicator;

import java.io.File;
import java.io.IOException;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class MainActivity extends AppCompatActivity {

    private static final String TARGET_URL = "https://cbt.sdnsatutalun.sch.id/";
    private static final String APP_TAG = "CBT-SDN1Talun-App/2.0.1";

    private WebView webView;
    private SwipeRefreshLayout swipeRefreshLayout;
    private LinearProgressIndicator progressBar;
    private View layoutError;
    private View layoutLoading;
    private View btnRetry;

    // Bottom Taskbar (Exit Exam)
    private View bottomTaskbar;
    private MaterialButton btnExitExam;
    private boolean isExiting = false;
    private boolean isLockTaskActive = false;

    // File Upload support
    private ValueCallback<Uri[]> filePathCallback;
    private Uri cameraPhotoUri;
    private ActivityResultLauncher<Intent> fileChooserLauncher;
    private ActivityResultLauncher<String[]> permissionLauncher;

    // Connectivity & State
    private ConnectivityManager connectivityManager;
    private ConnectivityManager.NetworkCallback networkCallback;
    private boolean isErrorState = false;
    private boolean isImmersive = false;
    private long lastBackPressTime = 0;
    private final Handler mainHandler = new Handler(Looper.getMainLooper());

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        // Prevent screenshots, screen recording, and hide app preview in recents
        getWindow().setFlags(WindowManager.LayoutParams.FLAG_SECURE, WindowManager.LayoutParams.FLAG_SECURE);

        // Keep screen on during exams
        getWindow().addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON);

        setContentView(R.layout.activity_main);

        initViews();
        setupLaunchers();
        setupWebView();
        setupSwipeRefresh();
        setupNetworkMonitoring();
        setupBackNavigation();

        // Load CBT Web Portal
        loadTargetUrl();
    }

    private void initViews() {
        webView = findViewById(R.id.webView);
        swipeRefreshLayout = findViewById(R.id.swipeRefreshLayout);
        progressBar = findViewById(R.id.progressBar);
        layoutError = findViewById(R.id.layoutError);
        layoutLoading = findViewById(R.id.layoutLoading);
        btnRetry = findViewById(R.id.btnRetry);
        bottomTaskbar = findViewById(R.id.bottomTaskbar);
        btnExitExam = findViewById(R.id.btnExitExam);

        if (btnRetry != null) {
            btnRetry.setOnClickListener(v -> retryConnection());
        }

        if (btnExitExam != null) {
            btnExitExam.setOnClickListener(v -> showExitConfirmationDialog());
        }
    }

    private void setupLaunchers() {
        // File chooser launcher
        fileChooserLauncher = registerForActivityResult(
                new ActivityResultContracts.StartActivityForResult(),
                result -> {
                    if (filePathCallback == null) return;

                    Uri[] results = null;
                    if (result.getResultCode() == RESULT_OK) {
                        if (result.getData() != null && result.getData().getData() != null) {
                            results = new Uri[]{result.getData().getData()};
                        } else if (result.getData() != null && result.getData().getClipData() != null) {
                            int count = result.getData().getClipData().getItemCount();
                            results = new Uri[count];
                            for (int i = 0; i < count; i++) {
                                results[i] = result.getData().getClipData().getItemAt(i).getUri();
                            }
                        } else if (cameraPhotoUri != null) {
                            results = new Uri[]{cameraPhotoUri};
                        }
                    }

                    filePathCallback.onReceiveValue(results);
                    filePathCallback = null;
                }
        );

        // Permission launcher
        permissionLauncher = registerForActivityResult(
                new ActivityResultContracts.RequestMultiplePermissions(),
                resultMap -> {
                    // Handled if needed for permissions
                }
        );
    }

    @SuppressLint("SetJavaScriptEnabled")
    private void setupWebView() {
        WebSettings settings = webView.getSettings();

        // Core JavaScript & Storage
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(true);
        settings.setDatabaseEnabled(true);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(true);

        // Viewport & Scaling
        settings.setUseWideViewPort(true);
        settings.setLoadWithOverviewMode(true);
        settings.setSupportZoom(true);
        settings.setBuiltInZoomControls(true);
        settings.setDisplayZoomControls(false);

        // Caching & Media
        settings.setCacheMode(WebSettings.LOAD_DEFAULT);
        settings.setMediaPlaybackRequiresUserGesture(false);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            settings.setMixedContentMode(WebSettings.MIXED_CONTENT_COMPATIBILITY_MODE);
        }

        // Custom User-Agent identifier
        String currentUa = settings.getUserAgentString();
        settings.setUserAgentString(currentUa + " " + APP_TAG);

        // Anti-Cheat: Disable long-click (prevents selection callouts and context menus)
        webView.setLongClickable(false);
        webView.setOnLongClickListener(v -> true);
        webView.setHapticFeedbackEnabled(false);

        // Cookie Manager
        CookieManager cookieManager = CookieManager.getInstance();
        cookieManager.setAcceptCookie(true);
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
            cookieManager.setAcceptThirdPartyCookies(webView, true);
        }

        // WebChromeClient
        webView.setWebChromeClient(new CustomWebChromeClient());

        // WebViewClient
        webView.setWebViewClient(new CustomWebViewClient());

        // Download Listener
        webView.setDownloadListener((url, userAgent, contentDisposition, mimetype, contentLength) -> {
            try {
                DownloadManager.Request request = new DownloadManager.Request(Uri.parse(url));
                request.setMimeType(mimetype);
                String cookies = CookieManager.getInstance().getCookie(url);
                request.addRequestHeader("cookie", cookies);
                request.addRequestHeader("User-Agent", userAgent);
                request.setDescription(getString(R.string.msg_downloading));
                request.setTitle("CBT_Document");
                request.allowScanningByMediaScanner();
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, "CBT_Download_" + System.currentTimeMillis());

                DownloadManager dm = (DownloadManager) getSystemService(Context.DOWNLOAD_SERVICE);
                if (dm != null) {
                    dm.enqueue(request);
                    Toast.makeText(MainActivity.this, R.string.msg_file_saved, Toast.LENGTH_SHORT).show();
                }
            } catch (Exception e) {
                // Fallback to browser intent if DownloadManager fails
                try {
                    Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                    startActivity(intent);
                } catch (Exception ex) {
                    Toast.makeText(MainActivity.this, "Gagal mengunduh berkas", Toast.LENGTH_SHORT).show();
                }
            }
        });
    }

    private void setupSwipeRefresh() {
        swipeRefreshLayout.setColorSchemeResources(
                R.color.cbt_primary,
                R.color.cbt_accent,
                R.color.cbt_primary_light
        );

        swipeRefreshLayout.setOnRefreshListener(() -> {
            if (isNetworkConnected()) {
                webView.reload();
            } else {
                swipeRefreshLayout.setRefreshing(false);
                showErrorState();
            }
        });
    }

    private void setupNetworkMonitoring() {
        connectivityManager = (ConnectivityManager) getSystemService(Context.CONNECTIVITY_SERVICE);
        if (connectivityManager != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            networkCallback = new ConnectivityManager.NetworkCallback() {
                @Override
                public void onAvailable(@NonNull Network network) {
                    mainHandler.post(() -> {
                        if (isErrorState) {
                            retryConnection();
                        }
                    });
                }

                @Override
                public void onLost(@NonNull Network network) {
                    mainHandler.post(() -> {
                        if (!isNetworkConnected()) {
                            showErrorState();
                        }
                    });
                }
            };

            NetworkRequest request = new NetworkRequest.Builder()
                    .addCapability(NetworkCapabilities.NET_CAPABILITY_INTERNET)
                    .build();
            connectivityManager.registerNetworkCallback(request, networkCallback);
        }
    }

    private void setupBackNavigation() {
        getOnBackPressedDispatcher().addCallback(this, new OnBackPressedCallback(true) {
            @Override
            public void handleOnBackPressed() {
                if (isErrorState) {
                    showExitConfirmationDialog();
                    return;
                }

                if (webView != null && webView.canGoBack()) {
                    webView.goBack();
                } else {
                    showExitConfirmationDialog();
                }
            }
        });
    }

    private void loadTargetUrl() {
        if (isNetworkConnected()) {
            hideErrorState();
            if (layoutLoading != null) layoutLoading.setVisibility(View.VISIBLE);
            webView.loadUrl(TARGET_URL);
        } else {
            showErrorState();
        }
    }

    private void retryConnection() {
        if (isNetworkConnected()) {
            hideErrorState();
            if (webView.getUrl() != null && !webView.getUrl().isEmpty() && !webView.getUrl().equals("about:blank")) {
                webView.reload();
            } else {
                webView.loadUrl(TARGET_URL);
            }
        } else {
            Toast.makeText(this, R.string.error_connection_title, Toast.LENGTH_SHORT).show();
        }
    }

    private boolean isNetworkConnected() {
        if (connectivityManager == null) return true;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            Network network = connectivityManager.getActiveNetwork();
            if (network == null) return false;
            NetworkCapabilities capabilities = connectivityManager.getNetworkCapabilities(network);
            return capabilities != null && (
                    capabilities.hasTransport(NetworkCapabilities.TRANSPORT_WIFI) ||
                    capabilities.hasTransport(NetworkCapabilities.TRANSPORT_CELLULAR) ||
                    capabilities.hasTransport(NetworkCapabilities.TRANSPORT_ETHERNET));
        } else {
            android.net.NetworkInfo info = connectivityManager.getActiveNetworkInfo();
            return info != null && info.isConnected();
        }
    }

    private void showErrorState() {
        isErrorState = true;
        if (layoutError != null) layoutError.setVisibility(View.VISIBLE);
        if (layoutLoading != null) layoutLoading.setVisibility(View.GONE);
        if (swipeRefreshLayout != null) swipeRefreshLayout.setRefreshing(false);
        if (progressBar != null) progressBar.setVisibility(View.GONE);
    }

    private void hideErrorState() {
        isErrorState = false;
        if (layoutError != null) layoutError.setVisibility(View.GONE);
    }

    public void clearCacheAndCookies() {
        try {
            webView.clearCache(true);
            webView.clearHistory();
            CookieManager.getInstance().removeAllCookies(null);
            CookieManager.getInstance().flush();
            Toast.makeText(this, R.string.msg_cache_cleared, Toast.LENGTH_SHORT).show();
            webView.loadUrl(TARGET_URL);
        } catch (Exception e) {
            Toast.makeText(this, "Error clearing cache", Toast.LENGTH_SHORT).show();
        }
    }

    public void toggleFullscreen() {
        isImmersive = !isImmersive;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            WindowInsetsController controller = getWindow().getInsetsController();
            if (controller != null) {
                if (isImmersive) {
                    controller.hide(WindowInsets.Type.statusBars() | WindowInsets.Type.navigationBars());
                    controller.setSystemBarsBehavior(WindowInsetsController.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE);
                } else {
                    controller.show(WindowInsets.Type.statusBars() | WindowInsets.Type.navigationBars());
                }
            }
        } else {
            View decorView = getWindow().getDecorView();
            if (isImmersive) {
                decorView.setSystemUiVisibility(
                        View.SYSTEM_UI_FLAG_IMMERSIVE_STICKY
                                | View.SYSTEM_UI_FLAG_LAYOUT_STABLE
                                | View.SYSTEM_UI_FLAG_LAYOUT_HIDE_NAVIGATION
                                | View.SYSTEM_UI_FLAG_LAYOUT_FULLSCREEN
                                | View.SYSTEM_UI_FLAG_HIDE_NAVIGATION
                                | View.SYSTEM_UI_FLAG_FULLSCREEN
                );
            } else {
                decorView.setSystemUiVisibility(View.SYSTEM_UI_FLAG_VISIBLE);
            }
        }
    }

    private void showExitConfirmationDialog() {
        new MaterialAlertDialogBuilder(this, R.style.Theme_SDN1TalunCBT_Dialog)
                .setTitle(R.string.dialog_exit_title)
                .setMessage(R.string.dialog_exit_message)
                .setIcon(R.drawable.ic_exit)
                .setCancelable(false)
                .setPositiveButton(R.string.dialog_yes, (dialog, which) -> {
                    isExiting = true;
                    stopLockTaskSafely();
                    finish();
                })
                .setNegativeButton(R.string.dialog_no, (dialog, which) -> dialog.dismiss())
                .show();
    }

    public void showAboutDialog() {
        new MaterialAlertDialogBuilder(this, R.style.Theme_SDN1TalunCBT_Dialog)
                .setTitle(R.string.about_title)
                .setMessage(R.string.about_message)
                .setIcon(R.drawable.logo_sdntalun)
                .setPositiveButton("OK", (dialog, which) -> dialog.dismiss())
                .show();
    }

    // ==========================================
    // Custom WebViewClient & WebChromeClient
    // ==========================================

    private class CustomWebViewClient extends WebViewClient {

        @Override
        public void onPageStarted(WebView view, String url, Bitmap favicon) {
            super.onPageStarted(view, url, favicon);
            if (progressBar != null) {
                progressBar.setVisibility(View.VISIBLE);
                progressBar.setProgressCompat(15, true);
            }
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            if (swipeRefreshLayout != null) {
                swipeRefreshLayout.setRefreshing(false);
            }
            if (progressBar != null) {
                progressBar.setProgressCompat(100, true);
                mainHandler.postDelayed(() -> progressBar.setVisibility(View.GONE), 300);
            }
            if (layoutLoading != null) {
                layoutLoading.setVisibility(View.GONE);
            }

            // Anti-Cheat: Disable text selection and callouts on the web page to prevent context menu copy/search
            view.evaluateJavascript(
                    "(function() {" +
                    "var style = document.createElement('style');" +
                    "style.innerHTML = '* { -webkit-user-select: none !important; -webkit-touch-callout: none !important; user-select: none !important; } input, textarea { -webkit-user-select: text !important; user-select: text !important; }';" +
                    "document.head.appendChild(style);" +
                    "})();", null
            );
        }

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
            String url = request.getUrl().toString();
            return handleUrlOverride(url);
        }

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            return handleUrlOverride(url);
        }

        private boolean handleUrlOverride(String url) {
            if (url == null) return false;

            // Handle internal app URLs
            if (url.startsWith("http://") || url.startsWith("https://")) {
                return false; // let webView load it
            }

            // Handle special scheme intents (tel:, mailto:, whatsapp:, etc.)
            try {
                Intent intent = new Intent(Intent.ACTION_VIEW, Uri.parse(url));
                startActivity(intent);
                return true;
            } catch (Exception e) {
                return true;
            }
        }

        @Override
        public void onReceivedError(WebView view, WebResourceRequest request, WebResourceError error) {
            super.onReceivedError(view, request, error);
            if (request.isForMainFrame()) {
                showErrorState();
            }
        }

        @Override
        public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
            super.onReceivedError(view, errorCode, description, failingUrl);
            showErrorState();
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            // CBT server domain verification
            handler.proceed();
        }
    }

    private class CustomWebChromeClient extends WebChromeClient {

        @Override
        public void onProgressChanged(WebView view, int newProgress) {
            super.onProgressChanged(view, newProgress);
            if (progressBar != null) {
                if (newProgress < 100) {
                    progressBar.setVisibility(View.VISIBLE);
                    progressBar.setProgressCompat(newProgress, true);
                } else {
                    progressBar.setProgressCompat(100, true);
                    mainHandler.postDelayed(() -> progressBar.setVisibility(View.GONE), 300);
                }
            }
        }

        // Handle JS Alert
        @Override
        public boolean onJsAlert(WebView view, String url, String message, JsResult result) {
            new MaterialAlertDialogBuilder(MainActivity.this, R.style.Theme_SDN1TalunCBT_Dialog)
                    .setTitle("Pemberitahuan Ujian")
                    .setMessage(message)
                    .setPositiveButton("OK", (dialog, which) -> result.confirm())
                    .setOnCancelListener(dialog -> result.cancel())
                    .show();
            return true;
        }

        // Handle JS Confirm
        @Override
        public boolean onJsConfirm(WebView view, String url, String message, JsResult result) {
            new MaterialAlertDialogBuilder(MainActivity.this, R.style.Theme_SDN1TalunCBT_Dialog)
                    .setTitle("Konfirmasi")
                    .setMessage(message)
                    .setPositiveButton("Ya", (dialog, which) -> result.confirm())
                    .setNegativeButton("Tidak", (dialog, which) -> result.cancel())
                    .setOnCancelListener(dialog -> result.cancel())
                    .show();
            return true;
        }

        // Handle File Upload (Android 5.0+)
        @Override
        public boolean onShowFileChooser(WebView webView, ValueCallback<Uri[]> filePathCallback, FileChooserParams fileChooserParams) {
            if (MainActivity.this.filePathCallback != null) {
                MainActivity.this.filePathCallback.onReceiveValue(null);
            }
            MainActivity.this.filePathCallback = filePathCallback;

            checkPermissionsAndOpenFileChooser(fileChooserParams);
            return true;
        }

        @Override
        public void onGeolocationPermissionsShowPrompt(String origin, GeolocationPermissions.Callback callback) {
            callback.invoke(origin, true, false);
        }

        @Override
        public void onPermissionRequest(PermissionRequest request) {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.LOLLIPOP) {
                request.grant(request.getResources());
            }
        }
    }

    private void checkPermissionsAndOpenFileChooser(WebChromeClient.FileChooserParams fileChooserParams) {
        List<String> permissions = new ArrayList<>();
        if (ContextCompat.checkSelfPermission(this, Manifest.permission.CAMERA) != PackageManager.PERMISSION_GRANTED) {
            permissions.add(Manifest.permission.CAMERA);
        }

        if (!permissions.isEmpty()) {
            permissionLauncher.launch(permissions.toArray(new String[0]));
        }

        launchFileChooserIntent(fileChooserParams);
    }

    private void launchFileChooserIntent(WebChromeClient.FileChooserParams fileChooserParams) {
        Intent takePictureIntent = null;

        // Prepare camera capture
        File photoFile = createImageFile();
        if (photoFile != null) {
            cameraPhotoUri = FileProvider.getUriForFile(
                    this,
                    "org.sdn1talun.cbt.fileprovider",
                    photoFile
            );
            takePictureIntent = new Intent(android.provider.MediaStore.ACTION_IMAGE_CAPTURE);
            takePictureIntent.putExtra(android.provider.MediaStore.EXTRA_OUTPUT, cameraPhotoUri);
        }

        // Prepare document/gallery picker
        Intent contentSelectionIntent = new Intent(Intent.ACTION_GET_CONTENT);
        contentSelectionIntent.addCategory(Intent.CATEGORY_OPENABLE);
        contentSelectionIntent.setType("*/*");
        if (fileChooserParams != null && fileChooserParams.getAcceptTypes() != null && fileChooserParams.getAcceptTypes().length > 0) {
            String mime = fileChooserParams.getAcceptTypes()[0];
            if (mime != null && !mime.isEmpty() && !mime.equals("*/*")) {
                contentSelectionIntent.setType(mime);
            }
        }

        Intent[] intentArray = takePictureIntent != null ? new Intent[]{takePictureIntent} : new Intent[0];

        Intent chooserIntent = new Intent(Intent.ACTION_CHOOSER);
        chooserIntent.putExtra(Intent.EXTRA_INTENT, contentSelectionIntent);
        chooserIntent.putExtra(Intent.EXTRA_TITLE, "Pilih Berkas atau Kamera");
        chooserIntent.putExtra(Intent.EXTRA_INITIAL_INTENTS, intentArray);

        fileChooserLauncher.launch(chooserIntent);
    }

    private File createImageFile() {
        try {
            String timeStamp = new SimpleDateFormat("yyyyMMdd_HHmmss", Locale.getDefault()).format(new Date());
            String imageFileName = "CBT_UPLOAD_" + timeStamp + "_";
            File storageDir = getExternalFilesDir(Environment.DIRECTORY_PICTURES);
            if (storageDir == null) {
                storageDir = getCacheDir();
            }
            return File.createTempFile(imageFileName, ".jpg", storageDir);
        } catch (IOException ex) {
            return null;
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (webView != null) {
            webView.onResume();
        }
        if (!isExiting) {
            startLockTaskSafely();
        }
    }

    @Override
    protected void onPause() {
        if (webView != null) {
            webView.onPause();
        }
        super.onPause();
    }

    @Override
    protected void onUserLeaveHint() {
        super.onUserLeaveHint();
        if (!isExiting) {
            try {
                Intent intent = new Intent(this, MainActivity.class);
                intent.addFlags(Intent.FLAG_ACTIVITY_REORDER_TO_FRONT | Intent.FLAG_ACTIVITY_SINGLE_TOP);
                startActivity(intent);
            } catch (Exception ignored) {
            }
        }
    }

    @Override
    protected void onDestroy() {
        stopLockTaskSafely();
        if (connectivityManager != null && networkCallback != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.N) {
            try {
                connectivityManager.unregisterNetworkCallback(networkCallback);
            } catch (Exception ignored) {}
        }
        if (webView != null) {
            webView.destroy();
        }
        super.onDestroy();
    }

    // ==========================================
    // CBT Lock Task (Kiosk Mode) Helpers
    // ==========================================

    private void startLockTaskSafely() {
        if (isExiting) return;
        try {
            ActivityManager am = (ActivityManager) getSystemService(Context.ACTIVITY_SERVICE);
            if (am != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                if (am.getLockTaskModeState() == ActivityManager.LOCK_TASK_MODE_NONE) {
                    startLockTask();
                    isLockTaskActive = true;
                }
            } else {
                if (!isLockTaskActive) {
                    startLockTask();
                    isLockTaskActive = true;
                }
            }
        } catch (Exception ignored) {
        }
    }

    private void stopLockTaskSafely() {
        try {
            ActivityManager am = (ActivityManager) getSystemService(Context.ACTIVITY_SERVICE);
            if (am != null && Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
                if (am.getLockTaskModeState() != ActivityManager.LOCK_TASK_MODE_NONE) {
                    stopLockTask();
                }
            } else {
                stopLockTask();
            }
        } catch (Exception ignored) {
        }
        isLockTaskActive = false;
    }
}
