package com.kaltura.client;


import com.google.gson.JsonElement;
import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import com.kaltura.client.types.KalturaAPIException;
import com.kaltura.client.utils.KalturaAPIConstants;
import com.kaltura.client.utils.ParseUtils;
import com.kaltura.client.utils.request.ActionsQueue;
import com.kaltura.client.utils.request.MultiActionRequest;
import com.kaltura.client.utils.request.RequestElement;
import com.kaltura.client.utils.request.interceptor.GzipInterceptor;
import com.kaltura.client.utils.request.interceptor.IdInterceptor;
import com.kaltura.client.utils.response.base.GeneralResponse;
import com.kaltura.client.utils.response.base.MultiResponse;
import okhttp3.*;
import okio.Buffer;

import java.io.FileNotFoundException;
import java.io.FileReader;
import java.io.IOException;
import java.util.concurrent.TimeUnit;

import static com.kaltura.client.utils.ParseUtils.parseResult;

/**
 * Created by tehilarozin on 21/07/2016.
 */
public class APIOkRequestsExecutor implements ActionsQueue {

    public static final String TAG = "ActionsQueue";
    public static final MediaType JSON_MediaType = MediaType.parse("application/json");
    public static final MediaType XML_MediaType = MediaType.parse("application/xml");

    /*private ConnectionConfiguration mKalturaConfig;
    private Map<String, Object> mClientConfig = new HashMap<String, Object>();
    private Map<String, Object> mRequestConfig = new HashMap<String, Object>();*/
    //private String mEndPoint = "http://54.154.22.171:8080/v4_0/api_v3";
    //private boolean useCompression = true;

    private OkHttpClient mOkClient;
    private boolean addSig;

    private static IKalturaLogger logger = KalturaLogger.getLogger(TAG);
    private IdInterceptor idInterceptor = new IdInterceptor();
    private GzipInterceptor gzipInterceptor = new GzipInterceptor();

    public APIOkRequestsExecutor(){
        getOkClient();
    }

    /*public boolean useCompression() {
        return useCompression;
    }

    public void setUseCompression(boolean useCompression) {
        this.useCompression = useCompression;
    }

*/
    private OkHttpClient getOkClient(ConnectionConfiguration configuration/*KalturaClientBase kalturaClientBase*/){
        if(configuration != null){
           // ConnectionConfiguration configuration = kalturaClientBase.getConnectionConfiguration();
            OkHttpClient.Builder builder = getOkClient().newBuilder()
                    .connectTimeout(configuration.getConnectTimeout(), TimeUnit.MILLISECONDS)
                    .readTimeout(configuration.getReadTimeout(), TimeUnit.MILLISECONDS)
                    .writeTimeout(configuration.getReadTimeout(), TimeUnit.MILLISECONDS)
                    .retryOnConnectionFailure((int)configuration.getParam(ConnectionConfiguration.MaxRetry, 1) > 0);

            if(!builder.interceptors().contains(idInterceptor)){
                builder.addInterceptor(idInterceptor);
            }
            if(configuration.getAcceptGzipEncoding() && !builder.interceptors().contains(gzipInterceptor)){
                builder.addInterceptor(gzipInterceptor);
            }

             mOkClient = builder.build();
        }
        return mOkClient;
    }

    private OkHttpClient getOkClient(){
        if(mOkClient == null){
            mOkClient = new OkHttpClient.Builder()
                    .connectionPool(new ConnectionPool()).build(); // default config - holds 5 connections up to 5 minutes idle time
        }
        return mOkClient;
    }

    /*public String queue(final RequestElement action) {
        return queue(action, null);
    }*/

    public String queue(final RequestElement action) {

        final Request request = buildRestRequest(action);
        try {
            Call call = getOkClient(action.config()).newCall(request);
            call.enqueue(new Callback() {
                @Override
                public void onFailure(Call call, IOException e) { //!! in case of request error on client side
                    if(call.isCanceled()){
                        logger.warn("onFailure: call "+call.request().tag()+" was canceled. not passing results");
                        return;
                    }
                    // handle failures: create response from exception
                    handleRequestError(action, request.tag().toString(), e, -1);
                }

                @Override
                public void onResponse(Call call, Response response) throws IOException {
                    if(call.isCanceled()){
                        logger.warn("call "+call.request().tag()+" was canceled. not passing results");
                        return;
                    }

                    // pass parsed response to action completion block
                    action.onComplete(parseResponse(response, action)); //!! in case the response.isSuccessful is false - status code can be checked
                }
            });
            return (String) call.request().tag();

        } catch (Exception e) {
            e.printStackTrace();
            handleRequestError(action, request.tag().toString(), e, -1);
        }
        //TODO - in case of exception - pass empty response
        return null; // no call id to return.
        //throw new KalturaAPIException(KalturaAPIException.FailureStep.OnRequest, "failed on request creation and queuing "+action.getUrlTail());
    }

    @Override
    public GeneralResponse execute(RequestElement action/*, ConnectionConfiguration config*/) {
        try {
            return parseResponse(getOkClient(action.config()).newCall(buildRestRequest(action)).execute(), action);

        }catch (IOException e){
            // failure on request execution - create error response
            return generateErrorResponse(action, KalturaAPIException.FailureStep.OnRequest, e, -1);
            //throw new KalturaAPIException(KalturaAPIException.FailureStep.OnRequest, e);
        }
    }

    @Override
    public void cancelAction(String callId) {
        Dispatcher dispatcher = getOkClient().dispatcher();
        for(Call call : dispatcher.queuedCalls()) {
            if(call.request().tag().equals(callId))
                call.cancel();
        }
        for(Call call : dispatcher.runningCalls()) {
            if(call.request().tag().equals(callId))
                call.cancel();
        }
    }

    @Override
    public void clearActions() {
        if(mOkClient != null) {
            mOkClient.dispatcher().cancelAll();
        }
    }

    @Override
    public boolean isEmpty() {
        return mOkClient == null || mOkClient.dispatcher().queuedCallsCount() == 0;
    }

    private void handleRequestError(final RequestElement action, String requestId, Exception e, int code){
        if(action != null) {
            GeneralResponse response = generateErrorResponse(action, e, code);
            response.requestId(requestId);
            action.onComplete(response);
        }
    }

    private static GeneralResponse generateErrorResponse(RequestElement action, Exception e, int code) {
        return generateErrorResponse(action, KalturaAPIException.FailureStep.OnResponse, e, code);
    }

    private static GeneralResponse generateErrorResponse(RequestElement action, KalturaAPIException.FailureStep failureStep, Exception e, int code) {
        KalturaAPIException result = e instanceof KalturaAPIException ? (KalturaAPIException) e : new KalturaAPIException(failureStep, e.getMessage(), code+"");

        return new GeneralResponse.Builder()
                .result(action instanceof MultiActionRequest ? new MultiResponse(result) : result)
                //.action(action.getAction())
                .requestId(action.getId())
                .build();

        /*return action instanceof MultiActionRequest ?
                new GeneralResponseList(action.getAction(), new MultiResponse(result)) : new GeneralSingleResponse(action.getAction(), result);*/
    }

    private GeneralResponse parseResponse(Response response, RequestElement action) {
        String requestId = getRequestId(response);

        try {
            if (!response.isSuccessful()) { // in case response has failure status
                handleRequestError(action, requestId, new KalturaAPIException(response.message()), response.code());

            } else {

                String responseString = response.body().string();
                logger.debug("response body:\n"+responseString);

                //responseString = responseString.replace("\"relatedObjects\":\"\"", "\"relatedObjects\":{}");

                String ContentType = getContentType(response.header(KalturaAPIConstants.HeaderContentType));

                GeneralResponse generalResponse = parseResult(responseString, ContentType);

                if(generalResponse != null) {
                    return generalResponse.requestId(requestId);
                }
            }

        } catch (IOException e) {
            e.printStackTrace();
        }

        return generateErrorResponse(action, new KalturaAPIException(KalturaAPIException.FailureStep.OnResponse, "Failed to parse response"), -1).requestId(requestId); // this method return type should be changed or method structure
    }

    private String getRequestId(Response response) {
        try {
            return response.request().tag().toString();
        }catch (NullPointerException e){
            return "";
        }
    }

    public static void mockResponse(String mockFile, RequestElement request) {
        JsonParser parser = new JsonParser();
        try {
            JsonElement element = (JsonObject) parser.parse(new FileReader(mockFile));
            GeneralResponse response = ParseUtils.parseResult(element.toString(), "json").requestId(request.getId());
            if (request != null) {
                request.onComplete(response);
            }
        }catch (FileNotFoundException e){
            request.onComplete(generateErrorResponse(request, e, -1));
        }
    }

    private String getContentType(String header) {
        return header.contains("application/xml") ? "xml" : "json";
    }


    /*private void prepareParams(RequestElement action, KalturaClientBase kalturaClientBase) {

        //?? do we need this:
        KalturaParams defaultParams = new KalturaParams();
       // defaultParams.add("format", kalturaClientBase.connectionConfiguration.getServiceResponseTypeFormat());
        defaultParams.add("ignoreNull", true);
        action.setParams(defaultParams);

        action.setParams(kalturaClientBase.clientConfiguration);
        action.setParams(kalturaClientBase.requestConfiguration);

        if (addSig) {
            ((KalturaParams) action.getParams()).add("kalsig", EncryptionUtils.encryptMD5(action.getBody()));
        }

    }
*/
    private Request buildQueryRequest(RequestElement action/*, KalturaClientBase kalturaClientBase*/) throws KalturaAPIException {
        //prepareParams(action, kalturaClientBase);
        // build url
        //KalturaParams params = (KalturaParams) action.getParams();
        //String url = params.toQueryString();

        return new Request.Builder()
                .headers(Headers.of(action.getHeaders()))// getHeaders(/*action*/kalturaClientBase.connectionConfiguration.getAcceptGzipEncoding()))
                //.method(action.getMethod(), body)
                .url(action.getUrl())
                .tag(action.getId())
                .build();
    }

    private Request buildRestRequest(RequestElement action/*, KalturaClientBase kalturaClientBase*/) {

        //prepareParams(action, kalturaClientBase);

        String url = action.getUrl();// kalturaClientBase.getConnectionConfiguration().getEndpoint()  + KalturaAPIConstants.UrlApiVersion + action.getUrlTail();
        System.out.println("request url: "+url +"\nrequest body:\n"+action.getBody()+"\n");

        String contentType = action.getContentType();
        MediaType mediaType = contentType == null || contentType.contains("json") ? JSON_MediaType : XML_MediaType;
        RequestBody body = getRequestBody(mediaType, action);
        /*action.isMultiparts() ? getMultipartRequest(action) :*/


        return new Request.Builder()
                .headers(Headers.of(action.getHeaders()))//getHeaders(/*action*/ kalturaClientBase.connectionConfiguration.getAcceptGzipEncoding()))
                .method(action.getMethod(), body)
                .url(url)
                .tag(action.tag())
                .build();
    }

   private RequestBody getRequestBody(MediaType contentType, RequestElement action) {
        //!! passing getBody as bytes to prevent OkHttp from adding "charset" definition to the "Content-Type" header declaration
        return RequestBody.create(contentType, action.getBody().getBytes());
    }

    /*private Headers getHeaders(*//*RequestElement action,*//* boolean acceptEncoding) {
        Headers headers = Headers.of(KalturaAPIConstants.HeaderAccept, "application/json",
                KalturaAPIConstants.HeaderAcceptCharset, "utf-8,ISO-8859-1;q=0.7,*;q=0.5");

        if(acceptEncoding) {
            headers.newBuilder().set(KalturaAPIConstants.HeaderAcceptEncoding, KalturaAPIConstants.HeaderEncodingGzip);
        }

        return headers;
    }*/

    public static String getRequestBody(Request request) {
        try {
            final Request copy = request.newBuilder().build();
            final Buffer buffer = new Buffer();
            copy.body().writeTo(buffer);
            return buffer.readUtf8();
        } catch (final IOException e) {
            return "did not work";
        }
    }

    /*private RequestBody getMultipartRequest(RequestElement action){

        KalturaFiles files = (KalturaFiles) action.getData("files");

        String boundary = "---------------------------" + System.currentTimeMillis();
        RequestBody requestBody = MultipartBody.create(MultipartBody.FORM,
                boundary+ )
                .setType()0
                //foreach file:
                .addFormDataPart("title", "Square Logo")
                .addFormDataPart("image", "logo-square.png",
                        RequestBody.create(MEDIA_TYPE_PNG, new File("website/static/logo-square.png")))
                .build();

        return requestBody;

    }*/

}
